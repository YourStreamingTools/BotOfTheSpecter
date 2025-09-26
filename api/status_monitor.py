#!/usr/bin/env python3
import asyncio
import aiomysql
import psutil
import os
import argparse
from dotenv import load_dotenv, find_dotenv

# Database configuration
load_dotenv(find_dotenv("/home/botofthespecter/.env"))
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER') 
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
DB_NAME = 'website'

# Parse command line arguments
parser = argparse.ArgumentParser(description='Monitor system metrics and update database.')
parser.add_argument('--server-name', required=True, help='Name of the server')
args = parser.parse_args()
SERVER_NAME = args.server_name

async def get_system_metrics():
    # CPU usage
    cpu_percent = psutil.cpu_percent(interval=1)
    # RAM usage
    memory = psutil.virtual_memory()
    ram_percent = memory.percent
    ram_used = memory.used / (1024**3)  # GB
    ram_total = memory.total / (1024**3)  # GB
    # Disk usage
    disk = psutil.disk_usage('/')
    disk_percent = disk.percent
    disk_used = disk.used / (1024**3)  # GB
    disk_total = disk.total / (1024**3)  # GB
    # Network usage (bytes sent/received in last second)
    net_io = psutil.net_io_counters()
    net_sent = net_io.bytes_sent / (1024**2)  # MB
    net_recv = net_io.bytes_recv / (1024**2)  # MB
    return {
        'cpu_percent': cpu_percent,
        'ram_percent': ram_percent,
        'ram_used': ram_used,
        'ram_total': ram_total,
        'disk_percent': disk_percent,
        'disk_used': disk_used,
        'disk_total': disk_total,
        'net_sent': net_sent,
        'net_recv': net_recv
    }

async def update_system_metrics(pool, server_name, metrics):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute("""
                INSERT INTO system_metrics (server_name, cpu_percent, ram_percent, ram_used, ram_total, disk_percent, disk_used, disk_total, net_sent, net_recv, last_updated)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
                ON DUPLICATE KEY UPDATE cpu_percent=%s, ram_percent=%s, ram_used=%s, ram_total=%s, disk_percent=%s, disk_used=%s, disk_total=%s, net_sent=%s, net_recv=%s, last_updated=NOW()
            """, (
                server_name,
                metrics['cpu_percent'],
                metrics['ram_percent'],
                metrics['ram_used'],
                metrics['ram_total'],
                metrics['disk_percent'],
                metrics['disk_used'],
                metrics['disk_total'],
                metrics['net_sent'],
                metrics['net_recv'],
                metrics['cpu_percent'],
                metrics['ram_percent'],
                metrics['ram_used'],
                metrics['ram_total'],
                metrics['disk_percent'],
                metrics['disk_used'],
                metrics['disk_total'],
                metrics['net_sent'],
                metrics['net_recv']
            ))
        await conn.commit()

async def main():
    # Create database pool
    pool = await aiomysql.create_pool(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=DB_NAME,
        autocommit=False
    )
    metrics = await get_system_metrics()
    await update_system_metrics(pool, SERVER_NAME, metrics)
    pool.close()
    await pool.wait_closed()

if __name__ == '__main__':
    asyncio.run(main())