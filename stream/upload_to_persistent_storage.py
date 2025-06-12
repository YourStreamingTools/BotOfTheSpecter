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

MULTIPART_THRESHOLD = 100 * 1024 * 1024  # 100MB

def multipart_upload_to_s3(file_path, bucket_name, s3_key, s3_client):
    try:
        logging.info(f"Starting multipart upload for file: {file_path}")
        config = boto3.s3.transfer.TransferConfig(multipart_threshold=MULTIPART_THRESHOLD,multipart_chunksize=MULTIPART_THRESHOLD)
        s3_client.upload_file(file_path, bucket_name, s3_key, Config=config)
        logging.info(f"Multipart upload complete for file: {file_path}")
    except Exception as e:
        logging.error(f"Multipart upload failed: {str(e)}")
        raise

def upload_to_s3(file_path, bucket_name, s3_key, s3_access_key, s3_secret_key, endpoint_url):
    try:
        logging.info(f"Starting upload for file: {file_path} to bucket: {bucket_name} with key: {s3_key}")
        # Initialize S3 client
        s3_client = boto3.client('s3',
            aws_access_key_id=s3_access_key,
            aws_secret_access_key=s3_secret_key,
            region_name="us-east-1",
            endpoint_url=endpoint_url
        )
        # Check file size
        file_size = os.path.getsize(file_path)
        if file_size >= MULTIPART_THRESHOLD:
            multipart_upload_to_s3(file_path, bucket_name, s3_key, s3_client)
        else:
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
    username = sys.argv[1]
    location = sys.argv[2]
    file_name = sys.argv[3]
    # Map Twitch server names to simplified S3 location names
    SERVER_TO_S3_LOCATION = {
        "sydney": "au",
        "us-west": "us", 
        "us-east": "us",
        "eu-central": "eu"
    }
    # Convert location to simplified S3 location name
    s3_location = SERVER_TO_S3_LOCATION.get(location, location)
    # Determine the current directory and navigate to the user's location folder
    current_dir = os.path.dirname(os.path.abspath(__file__))
    user_location_dir = os.path.join(current_dir, username, location)
    # AWS S3 configuration based on location
    s3_access_key = os.getenv(f"{s3_location}_s3_access_key")
    s3_secret_key = os.getenv(f"{s3_location}_s3_secret_key")
    endpoint_url = f'https://{os.getenv(f"{s3_location}_s3_bucket_url")}'
    # Check file exists and is >= 100MB
    file_path = os.path.join(user_location_dir, file_name)
    if not os.path.isfile(file_path):
        print(f"Error: File '{file_name}' not found in user location directory.")
        sys.exit(1)
    if os.path.getsize(file_path) < MULTIPART_THRESHOLD:
        print(f"Error: File '{file_name}' is less than 100MB. File must be at least 100MB for multipart upload.")
        sys.exit(1)
    # Upload file using multipart upload
    try:
        logging.info(f"Starting multipart upload for file: {file_path}")
        s3_client = boto3.client('s3',
            aws_access_key_id=s3_access_key,
            aws_secret_access_key=s3_secret_key,
            region_name="us-east-1",
            endpoint_url=endpoint_url
        )
        config = boto3.s3.transfer.TransferConfig(multipart_threshold=MULTIPART_THRESHOLD,multipart_chunksize=MULTIPART_THRESHOLD)
        s3_client.upload_file(file_path, username, f"{location}/{file_name}", Config=config)
        logging.info(f"Multipart upload complete for file: {file_path}")
        # Verify the file exists in S3
        response = s3_client.head_object(Bucket=username, Key=f"{location}/{file_name}")
        if response['ResponseMetadata']['HTTPStatusCode'] == 200:
            os.remove(file_path)
            logging.info(f"File '{file_path}' has been removed from the server.")
        else:
            logging.error(f"Unable to verify the upload of file '{file_path}' to S3.")
            print(f"Error: Unable to verify the upload of file '{os.path.basename(file_path)}' to S3.")
            sys.exit(1)
    except Exception as e:
        logging.error(f"An error occurred: {str(e)}")
        print(f"An error occurred while uploading file '{os.path.basename(file_path)}': {str(e)}")
        sys.exit(1)
