#!/bin/bash
# Pumpergy - Run the dashboard

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

VENV_DIR="venv"

PYTHON=$(command -v python3 || command -v python)

# Set activate path based on OS
if [[ "$OSTYPE" == "msys" || "$OSTYPE" == "cygwin" || "$OSTYPE" == "win32" ]]; then
    ACTIVATE="$VENV_DIR/Scripts/activate"
else
    ACTIVATE="$VENV_DIR/bin/activate"
fi

# Create venv if it doesn't exist
if [ ! -d "$VENV_DIR" ]; then
    echo "Creating virtual environment..."
    $PYTHON -m venv "$VENV_DIR"
fi

# Activate venv
source "$ACTIVATE"

# Install requirements if not already done (check for streamlit)
if ! python -c "import streamlit" 2>/dev/null; then
    echo "Installing requirements..."
    pip install -q -r requirements.txt
fi

# Data acquisition
if [[ "$1" == "--gdrive" || "$1" == "-g" ]]; then
    shift
    echo "Collecting CSV(s) from Google Drive..."
    python downloader_google.py
fi

if [[ "$1" == "--dl" || "$1" == "-d" ]]; then
    shift
    echo "Collecting CSV(s) from ~/Downloads/..."
    shopt -s nullglob
    files=(~/Downloads/EnergyData_*.csv)
    shopt -u nullglob
    if [ ${#files[@]} -gt 0 ]; then
        for f in "${files[@]}"; do
            echo "Moving $(basename "$f") to data/"
            mv "$f" ./data/
        done
    else
        echo "No new CSV exports found in ~/Downloads/"
    fi
fi

# Run the dashboard
echo "Starting Pumpergy dashboard..."
streamlit run app.py "$@"
