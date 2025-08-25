# Test API Interaction — PHP


A single-file PHP script that interacts with a test API to demonstrate HTTP communication and data processing skills.


## What it does
1. **First POST** to `https://test.icorp.uz/private/interview.php` with JSON body containing `msg` and `uri`. Receives the **first code part** (and optionally a URI for the next step).
2. **Second request** to the **designated URI** to obtain the **second code part**.
3. **Final POST** to the same endpoint with JSON body containing the concatenated `code` and prints the **final message**.


## Requirements
- PHP 7.4+ (tested up to PHP 8.3)
- cURL extension enabled (default in most PHP installs)


## Run (CLI)
```bash
php test_api_task.php \
--msg="Hello from candidate" \
--uri="/private/next" \
--endpoint="https://test.icorp.uz/private/interview.php" \
--timeout=15 \
--verbose