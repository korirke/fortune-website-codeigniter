#!/bin/bash

# Cron Job Setup Script for Fortune Kenya Job Expiry
# This script helps you set up the automated job expiry cron job

echo "=========================================="
echo "Fortune Kenya - Job Expiry Cron Job Setup"
echo "=========================================="
echo ""

# Get current directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

echo "Project Root: $PROJECT_ROOT"
echo ""

# Create log directory if it doesn't exist
LOG_DIR="/var/log/fortunekenya"
if [ ! -d "$LOG_DIR" ]; then
    echo "Creating log directory: $LOG_DIR"
    sudo mkdir -p "$LOG_DIR"
    sudo chown $USER:$USER "$LOG_DIR"
    sudo chmod 755 "$LOG_DIR"
fi

# Show cron job options
echo "Select cron job frequency:"
echo "1) Every hour"
echo "2) Every 30 minutes"
echo "3) Every 6 hours"
echo "4) Daily at 2 AM"
echo "5) Custom"
echo ""
read -p "Enter choice [1-5]: " choice

case $choice in
    1)
        CRON_SCHEDULE="0 * * * *"
        DESCRIPTION="Every hour"
        ;;
    2)
        CRON_SCHEDULE="*/30 * * * *"
        DESCRIPTION="Every 30 minutes"
        ;;
    3)
        CRON_SCHEDULE="0 */6 * * *"
        DESCRIPTION="Every 6 hours"
        ;;
    4)
        CRON_SCHEDULE="0 2 * * *"
        DESCRIPTION="Daily at 2 AM"
        ;;
    5)
        read -p "Enter custom cron schedule (e.g., '0 * * * *'): " CRON_SCHEDULE
        DESCRIPTION="Custom schedule"
        ;;
    *)
        echo "Invalid choice. Exiting."
        exit 1
        ;;
esac

# Build cron command
CRON_COMMAND="cd $PROJECT_ROOT && php spark jobs:check-expired >> $LOG_DIR/job-expiry.log 2>&1"
CRON_ENTRY="$CRON_SCHEDULE $CRON_COMMAND"

echo ""
echo "=========================================="
echo "Cron job to be added:"
echo "$CRON_ENTRY"
echo "Description: $DESCRIPTION"
echo "=========================================="
echo ""

read -p "Add this cron job? (y/n): " confirm

if [ "$confirm" != "y" ]; then
    echo "Cancelled. No changes made."
    exit 0
fi

# Add to crontab
(crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -

echo ""
echo "✓ Cron job added successfully!"
echo ""
echo "To view your crontab:"
echo "  crontab -l"
echo ""
echo "To edit your crontab manually:"
echo "  crontab -e"
echo ""
echo "To view logs:"
echo "  tail -f $LOG_DIR/job-expiry.log"
echo ""
echo "To test the command manually:"
echo "  cd $PROJECT_ROOT && php spark jobs:check-expired"
echo ""