#!/bin/bash

# Define the Magento binary path
BIN_MAGENTO="php bin/magento"
 

echo "--- 2. Cleaning Cache and Generated files ---"
rm -rf pub/static/frontend/Cosmotec/* pub/static/frontend/Magento/* var/view_preprocessed/*
$BIN_MAGENTO cache:flush


echo "--- 4. Static Content Deployment ---"
# -f forces deployment even in developer mode
$BIN_MAGENTO setup:static-content:deploy -f 

echo "--- 5. Final Cache Flush ---"
$BIN_MAGENTO cache:flush

echo "--- Deployment Complete! ---"