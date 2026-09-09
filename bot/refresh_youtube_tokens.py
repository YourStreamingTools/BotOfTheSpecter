#!/usr/bin/env python3
import os
import asyncio
import aiohttp
import aiomysql
import logging
from logging.handlers import RotatingFileHandler
from dotenv import load_dotenv

load_dotenv()

YOUTUBE_CLIENT_ID = os.getenv("YOUTUBE_CLIENT_ID") or os.getenv("GOOGLE_CLIENT_ID")
YOUTUBE_CLIENT_SECRET = os.getenv("YOUTUBE_CLIENT_SECRET") or os.getenv("GOOGLE_CLIENT_SECRET")
DB_HOST = os.getenv("SQL_HOST")
DB_USER = os.getenv("SQL_USER")
DB_PASS = os.getenv("SQL_PASSWORD")
DB_NAME = "website"
TOKEN_URL = "https://oauth2.googleapis.com/token"

log_dir = os.path.join(os.path.dirname(__file__), "logs")
os.makedirs(log_dir, exist_ok=True)
log_file = os.path.join(log_dir, "youtube_refresh.log")
logger = logging.getLogger("youtube_refresh")
logger.setLevel(logging.INFO)

file_handler = RotatingFileHandler(log_file, maxBytes=50 * 1024, backupCount=5)
file_handler.setFormatter(logging.Formatter("%(message)s"))
logger.addHandler(file_handler)

console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter("%(message)s"))
logger.addHandler(console_handler)


async def get_username(pool, user_id):
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT username FROM users WHERE id = %s", (user_id,))
                result = await cur.fetchone()
                return result[0] if result else None
    except Exception as e:
        print(f"⚠️  Failed to fetch username for user_id: {user_id} - {str(e)}")
        return None


def _has_upload_scope(scope_string):
    scopes = (scope_string or "").split()
    write = {
        "https://www.googleapis.com/auth/youtube.upload",
        "https://www.googleapis.com/auth/youtube",
        "https://www.googleapis.com/auth/youtube.force-ssl",
        "https://www.googleapis.com/auth/youtubepartner",
    }
    return 1 if any(s in write for s in scopes) else 0


async def refresh_youtube_token(session, pool, user_id, refresh_token):
    username = None
    try:
        username = await get_username(pool, user_id)
        username_display = f"{username}" if username else f"ID:{user_id}"
        data = {
            "grant_type": "refresh_token",
            "refresh_token": refresh_token,
            "client_id": YOUTUBE_CLIENT_ID,
            "client_secret": YOUTUBE_CLIENT_SECRET,
        }
        headers = {"Content-Type": "application/x-www-form-urlencoded"}
        async with session.post(TOKEN_URL, data=data, headers=headers) as response:
            result = await response.json(content_type=None)
            if response.status == 200 and "access_token" in result:
                new_access_token = result["access_token"]
                rotated = "refresh_token" in result
                new_refresh_token = result.get("refresh_token", refresh_token)
                granted_scopes = result.get("scope")
                try:
                    async with pool.acquire() as conn:
                        async with conn.cursor() as cur:
                            if granted_scopes is not None:
                                await cur.execute(
                                    "UPDATE youtube_tokens SET access_token = %s, refresh_token = %s, "
                                    "token_expiry = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %s SECOND), "
                                    "granted_scopes = %s, can_upload = %s, needs_reauth = 0 WHERE user_id = %s",
                                    (
                                        new_access_token,
                                        new_refresh_token,
                                        int(result.get("expires_in") or 3600),
                                        granted_scopes,
                                        _has_upload_scope(granted_scopes),
                                        user_id,
                                    ),
                                )
                            else:
                                await cur.execute(
                                    "UPDATE youtube_tokens SET access_token = %s, refresh_token = %s, "
                                    "token_expiry = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %s SECOND), "
                                    "needs_reauth = 0 WHERE user_id = %s",
                                    (
                                        new_access_token,
                                        new_refresh_token,
                                        int(result.get("expires_in") or 3600),
                                        user_id,
                                    ),
                                )
                            await conn.commit()
                except Exception as db_err:
                    logger.error(
                        f"🔥 YouTube token issued but DB persist FAILED for user: {username_display} - {str(db_err)}"
                    )
                    if rotated:
                        logger.error(
                            "   ⚠️  A rotated refresh token was NOT saved; this user may need to re-link YouTube."
                        )
                    return {"success": False, "username": username_display, "error": f"persist_failed: {db_err}"}
                if rotated:
                    logger.info(f"🔁 Rotated refresh token saved for user: {username_display}")
                return {"success": True, "username": username_display}
            if response.status in (400, 401) and result.get("error") == "invalid_grant":
                try:
                    async with pool.acquire() as conn:
                        async with conn.cursor() as cur:
                            await cur.execute(
                                "UPDATE youtube_tokens SET access_token = '', refresh_token = '', "
                                "needs_reauth = 1 WHERE user_id = %s",
                                (user_id,),
                            )
                            await conn.commit()
                except Exception as db_err:
                    logger.error(
                        f"🔥 Failed to discard invalid YouTube token for user: {username_display} - {str(db_err)}"
                    )
                logger.warning(
                    f"🚫 YouTube refresh token expired/revoked (invalid_grant) for user: {username_display} - discarded, flagged for re-auth"
                )
                return {"success": False, "username": username_display, "error": "invalid_grant", "reauth": True}
            error_msg = result.get("error", "Unknown error")
            error_desc = result.get("error_description", "")
            logger.error(f"❌ Failed to refresh YouTube token for user: {username_display}")
            logger.error(f"   Error: {error_msg} - {error_desc}")
            return {"success": False, "username": username_display, "error": f"{error_msg} - {error_desc}"}
    except Exception as e:
        logger.error(f"🔥 Exception refreshing YouTube token for user: {user_id} - {str(e)}")
        return {"success": False, "username": username or f"ID:{user_id}", "error": str(e)}


async def main():
    logger.info("🚀 Starting YouTube token refresh process...")
    if not YOUTUBE_CLIENT_ID or not YOUTUBE_CLIENT_SECRET:
        logger.error("❌ Missing YouTube client credentials in environment variables")
        logger.error("   Please set YOUTUBE_CLIENT_ID and YOUTUBE_CLIENT_SECRET")
        return
    if not DB_HOST or not DB_USER or not DB_PASS:
        logger.error("❌ Missing database credentials in environment variables")
        logger.error("   Please set SQL_HOST, SQL_USER, and SQL_PASSWORD")
        return
    try:
        pool = await aiomysql.create_pool(
            host=DB_HOST,
            user=DB_USER,
            password=DB_PASS,
            db=DB_NAME,
            autocommit=True,
        )
    except Exception as e:
        logger.error(f"❌ Failed to connect to database: {str(e)}")
        return
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT user_id, refresh_token FROM youtube_tokens "
                    "WHERE refresh_token IS NOT NULL AND refresh_token != '' AND needs_reauth = 0"
                )
                tokens = await cur.fetchall()
        if not tokens:
            logger.info("ℹ️  No YouTube users with refresh tokens found")
            return
        logger.info(f"📊 Found {len(tokens)} YouTube users with refresh tokens")
        async with aiohttp.ClientSession() as session:
            tasks = [
                refresh_youtube_token(session, pool, user_id, refresh_token)
                for user_id, refresh_token in tokens
            ]
            results = await asyncio.gather(*tasks, return_exceptions=True)
        successful = sum(1 for r in results if isinstance(r, dict) and r.get("success"))
        failed = len(results) - successful
        reauth_needed = sum(1 for r in results if isinstance(r, dict) and r.get("reauth"))
        logger.info("\n📈 YouTube token refresh completed:")
        logger.info(f"   ✅ Successful: {successful}")
        logger.info(f"   ❌ Failed: {failed}")
        if reauth_needed:
            logger.info(f"   🚫 Expired/revoked (discarded, need re-auth): {reauth_needed}")
        logger.info(f"   📊 Total: {len(results)}")
        if failed > 0:
            failed_users = [r.get("username", "Unknown") for r in results if isinstance(r, dict) and not r.get("success")]
            logger.warning(f"   ⚠️  Failed users: {', '.join(failed_users)}")
    except Exception as e:
        logger.error(f"❌ Error during token refresh process: {str(e)}")
    finally:
        pool.close()
        await pool.wait_closed()
        logger.info("🔒 Database connection closed")
        logger.info("")


if __name__ == "__main__":
    asyncio.run(main())
