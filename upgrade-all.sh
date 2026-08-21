#!/bin/bash

# Define the Magento binary path
BIN_MAGENTO="php bin/magento"
 

echo "--- 1. Cleaning Cache and Generated files ---"
rm -rf pub/static/frontend/Cosmotec/* pub/static/frontend/Magento/* var/cache/* var/page_cache/* var/view_preprocessed/* generated/code/* 
$BIN_MAGENTO cache:flush


echo "--- 2. upgrade ---"
# -f forces deployment even in developer mode
$BIN_MAGENTO setup:upgrade


echo "--- 3. compile ---"
# -f forces deployment even in developer mode
$BIN_MAGENTO setup:di:compile

echo "--- 4. Static Content Deployment ---"
# -f forces deployment even in developer mode
$BIN_MAGENTO setup:static-content:deploy -f 

echo "--- 5. Final Cache Flush ---"
$BIN_MAGENTO cache:flush

echo "--- Deployment Complete! ---"
