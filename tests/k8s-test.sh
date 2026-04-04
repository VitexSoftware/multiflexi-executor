#!/bin/bash

# Script to configure a RunTemplate for executing multiflexi-probe on a Kubernetes cluster
# This script uses multiflexi-cli to set up the necessary configuration

set -euo pipefail

# Configuration variables
RUNTEMPLATE_NAME="k8s-multiflexi-probe"
RUNTEMPLATE_DESCRIPTION="Execute multiflexi-probe on Kubernetes cluster"
PROBE_COMMAND="multiflexi-probe"
KUBERNETES_NAMESPACE="${KUBERNETES_NAMESPACE:-default}"
KUBERNETES_POD_IMAGE="${KUBERNETES_POD_IMAGE:-multiflexi/probe:latest}"

# Error handling
error_exit() {
    echo "Error: $1" >&2
    exit 1
}

# Check if multiflexi-cli is available
command -v multiflexi-cli &> /dev/null || error_exit "multiflexi-cli is not installed or not in PATH"

echo "Configuring RunTemplate for Kubernetes-based multiflexi-probe execution..."

# Create or update RunTemplate
multiflexi-cli runtemplate:create \
    --name="$RUNTEMPLATE_NAME" \
    --description="$RUNTEMPLATE_DESCRIPTION" \
    --script="$PROBE_COMMAND" \
    --type="kubernetes" \
    2>&1 || error_exit "Failed to create RunTemplate"

echo "RunTemplate '$RUNTEMPLATE_NAME' configured successfully"

# Configure Kubernetes execution parameters
multiflexi-cli runtemplate:set-param \
    --name="$RUNTEMPLATE_NAME" \
    --param="namespace" \
    --value="$KUBERNETES_NAMESPACE" \
    2>&1 || error_exit "Failed to set Kubernetes namespace parameter"

multiflexi-cli runtemplate:set-param \
    --name="$RUNTEMPLATE_NAME" \
    --param="image" \
    --value="$KUBERNETES_POD_IMAGE" \
    2>&1 || error_exit "Failed to set Kubernetes image parameter"

echo "Kubernetes parameters configured successfully"
echo "RunTemplate is ready for execution"