#!/bin/bash

# Flow Payment Callback Test Script
# Usage: ./test-callback.sh [your-domain.com] [optional-token]

# Set default values
DOMAIN=${1:-"localhost"}
TOKEN=${2:-"test_token_$(date +%s)"}
CALLBACK_URL="https://$DOMAIN/wp-json/flow/v1/payment-callback"

echo "🧪 Testing Flow Payment Callback"
echo "================================="
echo "Domain: $DOMAIN"
echo "Token: $TOKEN"
echo "Callback URL: $CALLBACK_URL"
echo ""

# Test 1: Valid POST request with token
echo "Test 1: Valid POST request with token"
echo "--------------------------------------"
response=$(curl -s -w "\nHTTP_CODE:%{http_code}\n" \
  -X POST "$CALLBACK_URL" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "User-Agent: Flow-Test-Client" \
  -d "token=$TOKEN")

echo "Response:"
echo "$response"
echo ""

# Test 2: Missing token
echo "Test 2: Missing token (should return 400)"
echo "------------------------------------------"
response=$(curl -s -w "\nHTTP_CODE:%{http_code}\n" \
  -X POST "$CALLBACK_URL" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "User-Agent: Flow-Test-Client" \
  -d "")

echo "Response:"
echo "$response"
echo ""

# Test 3: GET request (should not work)
echo "Test 3: GET request (should not work)"
echo "--------------------------------------"
response=$(curl -s -w "\nHTTP_CODE:%{http_code}\n" \
  -X GET "$CALLBACK_URL?token=$TOKEN")

echo "Response:"
echo "$response"
echo ""

# Test 4: Check if endpoint exists
echo "Test 4: Check endpoint availability"
echo "-----------------------------------"
response=$(curl -s -w "\nHTTP_CODE:%{http_code}\n" \
  -X OPTIONS "$CALLBACK_URL")

echo "Response:"
echo "$response"
echo ""

echo "✅ Testing complete!"
echo ""
echo "Expected results:"
echo "- Test 1: Should return 200 or 400/500 (depending on Flow API response)"
echo "- Test 2: Should return 400 with 'Token is required' error"
echo "- Test 3: Should return 404 (GET not supported)"
echo "- Test 4: Should return 200 or 405 (endpoint exists)"
echo ""
echo "💡 Tips:"
echo "- Check WordPress debug.log for detailed error messages"
echo "- Use 'tail -f wp-content/debug.log' to monitor logs in real-time"
echo "- Make sure WordPress REST API is enabled"