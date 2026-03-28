# This script uses the Google Drive API to 
# 1. download CSV files from a specified folder and save them to the ./data/ folder.
# 2. move the downloaded files to an archive folder on Google Drive.
#   (only file owner can delete files, so we move them instead of deleting)
#
# Usage:
#   python downloader_google.py              Download from folderId, then move to archive.
#   python downloader_google.py --archived   Download from archiveFolderId without moving files.

import argparse
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload
from google.oauth2.service_account import Credentials
import json

parser = argparse.ArgumentParser(description="Download CSV files from Google Drive.")
parser.add_argument(
    "--archived", action="store_true",
    help="Import from the archive folder instead, without moving any files."
)
args = parser.parse_args()

SCOPES = ["https://www.googleapis.com/auth/drive"]

try:
    config = json.load(open("downloader_google.json"))
except FileNotFoundError:
    print("Configuration file downloader_google.json not found.")
    print("Do you want me to create a template for you? (y/n)")
    answer = input().strip().lower()
    if answer == "y":
        template = """{
  "folderId": "<the folder id where the csv files are stored>",
  "archiveFolderId": "<the folder id where the csv files should be moved after processing>",
  "serviceAccount": { <Copy content from service account credentials json file here> }
}"""
        with open("downloader_google.json", "w") as f:
            f.write(template)
        print("Template created. Please fill in the required fields in downloader_google.json.")
    exit(1)

except Exception as e:
    print(f"Failed to load configuration from downloader_google.json: {e}")
    exit(1)
    
# The CONFIG holds a structure like:
# {
#   "folderId": "<the folder id where the csv files are stored>",
#   "archiveFolderId": "<the folder id where the csv files should be moved after processing>",
#   "serviceAccount":  <the service account credentials json content> 
# }

source_folder_id = config["archiveFolderId"] if args.archived else config["folderId"]

creds = Credentials.from_service_account_info(config["serviceAccount"], scopes=SCOPES)
service = build("drive", "v3", credentials=creds)

results = service.files().list(
    q=f"'{source_folder_id}' in parents",
    fields="files(id, name)"
).execute()

files = results.get("files", [])

if not files:
    print("No files found in Google Drive to download.")
else:
    for f in files:    
        file_id = f["id"]
        request = service.files().get_media(fileId=file_id)
        with open(f"data/{f['name']}", "wb") as fh:
            downloader = MediaIoBaseDownload(fh, request)
            done = False
            while not done:
                status, done = downloader.next_chunk()

        if args.archived:
            print(f"Processed {f['name']} (archived).")
        else:
            try:
                file = service.files().get(fileId=file_id, fields="parents").execute()
                previous_parents = ",".join(file.get("parents"))

                service.files().update(
                    fileId=file_id,
                    addParents=config["archiveFolderId"],
                    removeParents=previous_parents
                ).execute()

                print(f"Processed {f['name']}.")
            except Exception as e:
                print(f"Failed to move {f['name']} to archive folder on Google Drive: {e}\n")

