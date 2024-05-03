import argparse
import sqlite3

parser = argparse.ArgumentParser(description="Migrate SQLite to MySQL")
parser.add_argument("-channel", dest="channel", help="Enter the channel name to migrate over to MySQL")
args = parser.parse_args()
channel = args.channel
sqlite_db_file = f"/var/www/bot/commands/{channel}.db"
mysql_sql_file = f'/var/www/bot/sql/{channel}.sql'

# Function to convert SQLite database to MySQL-compatible SQL file
def convert_sqlite_to_mysql(sqlite_db_file, mysql_sql_file):
    # Connect to SQLite database
    sqlite_conn = sqlite3.connect(sqlite_db_file)
    sqlite_cursor = sqlite_conn.cursor()

    # Export SQLite database to SQL file
    with open(mysql_sql_file, 'w') as f:
        for line in sqlite_conn.iterdump():
            f.write('%s\n' % line)

    # Close SQLite connection
    sqlite_conn.close()

# Convert SQLite to MySQL-compatible SQL file
convert_sqlite_to_mysql(sqlite_db_file, mysql_sql_file)