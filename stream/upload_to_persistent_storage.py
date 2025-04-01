import boto3
import os
import sys
import logging
from botocore.exceptions import NoCredentialsError, PartialCredentialsError
from dotenv import load_dotenv

# Configure logging
logging.basicConfig(
    filename='upload_to_s3.log',
    level=logging.DEBUG,
    format='%(asctime)s - %(levelname)s - %(message)s'
)

# Load environment variables from .env file
load_dotenv()

def upload_to_s3(file_path, bucket_name, s3_key, aws_access_key, aws_secret_key, endpoint_url):
    try:
        logging.info(f"Starting upload for file: {file_path} to bucket: {bucket_name} with key: {s3_key}")
        # Initialize S3 client
        s3_client = boto3.client('s3',
            aws_access_key_id=aws_access_key,
            aws_secret_access_key=aws_secret_key,
            region_name="us-east-1",
            endpoint_url=endpoint_url
        )
        # Upload file
        s3_client.upload_file(file_path, bucket_name, s3_key)
        logging.info(f"File '{file_path}' successfully uploaded to bucket '{bucket_name}' with key '{s3_key}'.")
        # Verify the file exists in S3
        response = s3_client.head_object(Bucket=bucket_name, Key=s3_key)
        if response['ResponseMetadata']['HTTPStatusCode'] == 200:
            # Remove the file after successful upload and verification
            os.remove(file_path)
            logging.info(f"File '{file_path}' has been removed from the server.")
        else:
            logging.error(f"Unable to verify the upload of file '{file_path}' to S3.")
            print(f"Error: Unable to verify the upload of file '{os.path.basename(file_path)}' to S3.")
            sys.exit(1)
    except FileNotFoundError:
        logging.error(f"File '{file_path}' not found.")
        print(f"Error: File '{file_path}' not found.")
        sys.exit(1)
    except NoCredentialsError:
        logging.error("AWS credentials not provided.")
        print("Error: AWS credentials not provided.")
        sys.exit(1)
    except PartialCredentialsError:
        logging.error("Incomplete AWS credentials provided.")
        print("Error: Incomplete AWS credentials provided.")
        sys.exit(1)
    except Exception as e:
        logging.error(f"An error occurred: {str(e)}")
        print(f"An error occurred while uploading file '{os.path.basename(file_path)}': {str(e)}")
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python3 upload_to_persistent_storage.py <username> <file_name>")
        sys.exit(1)
    username = sys.argv[1]
    file_name = sys.argv[2]
    # Determine the current directory and navigate to the user's folder
    current_dir = os.path.dirname(os.path.abspath(__file__))
    user_dir = os.path.join(current_dir, username)
    # Construct the full file path
    file_path = os.path.join(user_dir, file_name)
    # AWS S3 configuration
    aws_access_key = os.getenv("AWS_ACCESS_KEY")
    aws_secret_key = os.getenv("AWS_SECRET_KEY")
    endpoint_url = f'https://{os.getenv("AWS_ENDPOINT_URL")}'
    # Upload the file
    upload_to_s3(file_path, username, file_name, aws_access_key, aws_secret_key, endpoint_url)
