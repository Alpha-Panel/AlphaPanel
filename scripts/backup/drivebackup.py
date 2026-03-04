import os
import sys
import json
import argparse
from datetime import datetime, timedelta
from google.oauth2 import service_account
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError
from googleapiclient.http import MediaFileUpload, MediaIoBaseDownload
from google.auth.transport.requests import Request
from io import BytesIO

# Parse command-line arguments
parser = argparse.ArgumentParser(description='Sync files with Google Drive')
parser.add_argument('--upload_path', type=str, help='Google Drive path to upload files')
parser.add_argument('--local_path', type=str, help='Local path to the files to sync')
parser.add_argument('--remove-old-backups', action='store_true', help='Remove backups older than 3 weeks')
parser.add_argument('--local_auth', action='store_true', help='Authenticate using local OAuth2 instead of Service Account')
args = parser.parse_args()

settings_file = 'settings.json'
settings = json.load(open(settings_file))

# Replace with your own values
SAVE_FOLDER_PATH = args.local_path
SERVICE_ACCOUNT_FILE = 'service_account.json'
CREDENTIALS_FILE = 'credentials.json'
YOUR_FOLDER_ID = settings['drive_folder_id']

# Google Drive API settings
SCOPES = ["https://www.googleapis.com/auth/drive"]
FOLDER_MIME_TYPE = "application/vnd.google-apps.folder"

# Authenticate using the Google Drive API
creds = None

if args.local_auth:
    # Local OAuth2 Authentication
    if os.path.exists('token.json'):
        creds = Credentials.from_authorized_user_file('token.json', SCOPES)
    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
        else:
            flow = InstalledAppFlow.from_client_secrets_file(CREDENTIALS_FILE, SCOPES)
            creds = flow.run_local_server(port=0)
        with open('token.json', 'w') as token:
            token.write(creds.to_json())
else:
    # Service Account Authentication
    creds = service_account.Credentials.from_service_account_file(
        SERVICE_ACCOUNT_FILE, scopes=SCOPES)

# Create the Google Drive API client
service = build("drive", "v3", credentials=creds)

# Create a folder in Google Drive, handling nested folders
def create_drive_folder(path, parent_folder_id):
    folder_ids = {}
    current_parent = parent_folder_id

    for folder_name in path.split('/'):
        if folder_name not in folder_ids:
            query = f"mimeType='{FOLDER_MIME_TYPE}' and trashed = false and name='{folder_name}' and '{current_parent}' in parents"
            response = service.files().list(q=query, spaces="drive", fields="files(id, name)").execute()
            folders = response.get("files")

            if folders:
                current_folder_id = folders[0].get('id')
            else:
                folder_metadata = {
                    "name": folder_name,
                    "mimeType": FOLDER_MIME_TYPE,
                    "parents": [current_parent]
                }
                folder = service.files().create(body=folder_metadata, fields="id").execute()
                current_folder_id = folder.get("id")

            folder_ids[folder_name] = current_folder_id
        current_parent = folder_ids[folder_name]

    return current_parent

# Remove old backups
def remove_old_backups(folder_id):
    three_weeks_ago = datetime.now() - timedelta(weeks=3)

    query = f"mimeType!='{FOLDER_MIME_TYPE}' and trashed = false and '{folder_id}' in parents"
    response = service.files().list(q=query, spaces="drive", fields="files(id, name, modifiedTime)").execute()
    files = response.get("files", [])

    for file in files:
        file_modified_time = datetime.strptime(file['modifiedTime'], "%Y-%m-%dT%H:%M:%S.%fZ")
        if file_modified_time < three_weeks_ago:
            print(f"Deleting old backup: {file['name']}")
            service.files().delete(fileId=file['id']).execute()

# Determine the folder ID to use
upload_folder_id = YOUR_FOLDER_ID
if args.upload_path:
    upload_folder_id = create_drive_folder(args.upload_path, YOUR_FOLDER_ID)

# If --remove-old-backups is set, remove old backups
if args.remove_old_backups:
    remove_old_backups(upload_folder_id)
    sys.exit(0)

# Helper function to get the file timestamp
def get_file_timestamp(file_path):
    stat = os.stat(file_path)
    return datetime.utcfromtimestamp(stat.st_mtime)

# Helper function to download a file from Google Drive
def download_drive_file(file_id, local_path):
    request = service.files().get_media(fileId=file_id)
    file_data = BytesIO()
    downloader = MediaIoBaseDownload(file_data, request)
    done = False
    while not done:
        status, done = downloader.next_chunk()
        print("Download %d%%." % int(status.progress() * 100))

    with open(local_path, "wb") as local_file:
        local_file.write(file_data.getvalue())

# Helper function to upload a large file to Google Drive with chunking
CHUNK_SIZE = 100 * 1024 * 1024  # 100MB

def upload_large_file(service, local_file_path, drive_folder_id):
    file_metadata = {
        "name": os.path.basename(local_file_path),
        "parents": [drive_folder_id]
    }
    media = MediaFileUpload(local_file_path, chunksize=CHUNK_SIZE, resumable=True)
    request = service.files().create(body=file_metadata, media_body=media, fields="id")
    
    response = None
    while response is None:
        try:
            status, response = request.next_chunk()
            if status:
                print(f"Uploaded {int(status.progress() * 100)}% of {local_file_path}")
        except HttpError as error:
            if error.resp.status in [403, 429]:
                print(f"Quota exceeded or rate limit error for {local_file_path}. Retrying...")
            else:
                raise
    
    print(f"Upload Complete for {local_file_path}. File ID: {response.get('id')}")

# Sync local game save files with Google Drive
for root, dirs, files in os.walk(SAVE_FOLDER_PATH):
    for file in files:
        local_file_path = os.path.join(root, file)
        relative_path = os.path.relpath(local_file_path, SAVE_FOLDER_PATH)

        query = f"mimeType!='{FOLDER_MIME_TYPE}' and trashed = false and name='{relative_path}' and '{upload_folder_id}' in parents"
        response = service.files().list(q=query, spaces="drive", fields="nextPageToken, files(id, name, mimeType, modifiedTime)").execute()
        drive_files = response.get("files")

        if drive_files:
            drive_file = drive_files[0]
            drive_file_id = drive_file["id"]
            drive_file_timestamp = datetime.strptime(drive_file["modifiedTime"], "%Y-%m-%dT%H:%M:%S.%fZ")

            local_file_timestamp = get_file_timestamp(local_file_path)
            if local_file_timestamp > drive_file_timestamp:
                print(f"Uploading {relative_path} to Google Drive")
                service.files().delete(fileId=drive_file_id).execute()
                upload_large_file(service, local_file_path, upload_folder_id)
            elif local_file_timestamp < drive_file_timestamp:
                print(f"Downloading {relative_path} from Google Drive")
                download_drive_file(drive_file_id, local_file_path)
            else:
                print(f"{relative_path} is already in sync")
        else:
            print(f"Uploading {relative_path} to Google Drive")
            upload_large_file(service, local_file_path, upload_folder_id)
