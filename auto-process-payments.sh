#!/bin/bash

# Ultra-portable Auto Payment Processing Script for Plesk environments
# This script uses only PHP and basic POSIX shell features

# Configuration - use relative paths since we might not have pwd/dirname
SCRIPT_DIR="."
PROJECT_DIR=".."
LOG_DIR="$SCRIPT_DIR/log"
LOG_FILE="$LOG_DIR/auto-payment-processing.log"

# Create log directory if it doesn't exist
if [ ! -d "$LOG_DIR" ]; then
    mkdir -p "$LOG_DIR" 2>/dev/null || mkdir "$LOG_DIR" 2>/dev/null || true
fi

# Function to log with timestamp using only PHP
log() {
    /.phpenv/versions/8.3/bin/php -r "
        \$timestamp = date('Y-m-d H:i:s');
        \$message = '[\$timestamp] $1';
        echo \$message . PHP_EOL;
        file_put_contents('$LOG_FILE', \$message . PHP_EOL, FILE_APPEND | LOCK_EX);
    "
}

# Function to run console command with logging
run_command() {
    local cmd="$1"
    local description="$2"
    
    log "Starting: $description"
    log "Command: $cmd"
    
    # Run command and capture exit code
    if eval "$cmd" >> "$LOG_FILE" 2>&1; then
        log "✅ Success: $description"
        return 0
    else
        local exit_code=$?
        log "❌ Failed: $description (exit code: $exit_code)"
        return $exit_code
    fi
}

# Change to project directory if it exists
if [ -d "$PROJECT_DIR" ]; then
    cd "$PROJECT_DIR" || exit 1
fi

# Set environment for production
export APP_ENV=prod
export KLMS_IDM_SSL_VERIFY=false

log "=== Auto Payment Processing Started ==="
log "Working directory: $(/.phpenv/versions/8.3/bin/php -r 'echo getcwd();')"
log "Environment: APP_ENV=$APP_ENV, KLMS_IDM_SSL_VERIFY=$KLMS_IDM_SSL_VERIFY"

# Calculate date 2 days ago using PHP
SINCE_DATE=$(/.phpenv/versions/8.3/bin/php -r "echo date('Y-m-d', strtotime('-2 days'));")
log "Processing payments since: $SINCE_DATE"

# Step 1: Fetch PayPal emails from last 2 days
log "--- Step 1: Fetching PayPal emails ---"
FETCH_CMD="/.phpenv/versions/8.3/bin/php bin/console app:fetch-payment-emails --since=$SINCE_DATE --source=paypal --limit=50 --env=prod"
if ! run_command "$FETCH_CMD" "Fetch PayPal emails"; then
    log "❌ Email fetching failed, aborting"
    exit 1
fi

# Step 2: Match unmatched payments automatically
log "--- Step 2: Matching unmatched payments ---"
MATCH_CMD="/.phpenv/versions/8.3/bin/php bin/console app:process-payments --match-only --limit=20 --env=prod"
if ! run_command "$MATCH_CMD" "Match unmatched payments"; then
    log "⚠️ Payment matching had issues, but continuing"
fi

# Step 3: Process matched payments (tickets first, then catering)
log "--- Step 3: Processing matched payments ---"
PROCESS_CMD="/.phpenv/versions/8.3/bin/php bin/console app:process-payments --process-only --limit=20 --env=prod"
if ! run_command "$PROCESS_CMD" "Process matched payments"; then
    log "⚠️ Payment processing had issues, but continuing"
fi

log "=== Auto Payment Processing Completed ==="
log ""
