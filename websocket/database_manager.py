import os
import aiomysql

class DatabaseManager:
    def __init__(self, logger):
        self.logger = logger

    async def get_connection(self, database_name='website'):
        try:
            # Get database configuration from environment variables
            db_host = os.getenv('SQL_HOST')
            db_user = os.getenv('SQL_USER')
            db_password = os.getenv('SQL_PASSWORD')
            db_port = os.getenv('SQL_PORT')
            # Validate required environment variables
            if not all([db_host, db_user, db_password, db_port]):
                missing_vars = []
                if not db_host: missing_vars.append('SQL_HOST')
                if not db_user: missing_vars.append('SQL_USER')
                if not db_password: missing_vars.append('SQL_PASSWORD')
                if not db_port: missing_vars.append('SQL_PORT')
                self.logger.error(f"✗ Missing required environment variables: {', '.join(missing_vars)}")
                return None
            try:
                db_port = int(db_port)
            except ValueError:
                self.logger.error(f"✗ Invalid SQL_PORT value: {db_port}. Must be a number.")
                return None
            conn = await aiomysql.connect(
                host=db_host,
                user=db_user,
                password=db_password,
                db=database_name,
                port=db_port,
                autocommit=True
            )
            self.logger.info(f"✓ Database connection established to {db_host}:{db_port} for database '{database_name}'")
            return conn
        except Exception as e:
            self.logger.error(f"✗ Failed to connect to database: {e}")
            return None

    async def execute_query(self, query, params=None, database_name='website'):
        conn = None
        try:
            conn = await self.get_connection(database_name)
            if not conn:
                return None
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(query, params)
                if query.strip().upper().startswith('SELECT'):
                    result = await cursor.fetchall()
                    return result
                else:
                    return cursor.rowcount
        except Exception as e:
            self.logger.error(f"Database query error: {e}")
            return None
        finally:
            if conn:
                conn.close()

    async def get_user_settings(self, channel_name):
        query = "SELECT * FROM profile WHERE id = 1"
        result = await self.execute_query(query, database_name=channel_name)
        return result[0] if result else None

    async def get_user_api_key_info(self, api_key):
        try:
            query = "SELECT username, twitch_user_id FROM users WHERE api_key = %s"
            result = await self.execute_query(query, (api_key,), 'website')
            return result[0] if result else None
        except Exception as e:
            self.logger.error(f"Failed to get user API key info: {e}")
            return None

    async def test_connection(self):
        self.logger.info("Testing database connection...")
        try:
            conn = await self.get_connection('website')
            if conn:
                async with conn.cursor() as cursor:
                    await cursor.execute("SELECT 1")
                    result = await cursor.fetchone()
                    if result:
                        self.logger.info("✓ Database connection test successful")
                        return True
                conn.close()
            else:
                self.logger.error("✗ Database connection test failed")
                return False
        except Exception as e:
            self.logger.error(f"✗ Database connection test error: {e}")
            return False

    async def insert_record(self, table, data, database_name='website'):
        try:
            columns = ', '.join(data.keys())
            placeholders = ', '.join(['%s'] * len(data))
            values = tuple(data.values())
            query = f"INSERT INTO {table} ({columns}) VALUES ({placeholders})"
            result = await self.execute_query(query, values, database_name)
            return result
        except Exception as e:
            self.logger.error(f"Error inserting record into {table}: {e}")
            return None

    async def update_record(self, table, data, where_clause, where_params=None, database_name='website'):
        try:
            set_clause = ', '.join([f"{col} = %s" for col in data.keys()])
            values = list(data.values())
            if where_params:
                values.extend(where_params)
            query = f"UPDATE {table} SET {set_clause} WHERE {where_clause}"
            result = await self.execute_query(query, values, database_name)
            return result
        except Exception as e:
            self.logger.error(f"Error updating record in {table}: {e}")
            return None

    async def delete_record(self, table, where_clause, where_params=None, database_name='website'):
        try:
            query = f"DELETE FROM {table} WHERE {where_clause}"
            result = await self.execute_query(query, where_params, database_name)
            return result
        except Exception as e:
            self.logger.error(f"Error deleting record from {table}: {e}")
            return None
