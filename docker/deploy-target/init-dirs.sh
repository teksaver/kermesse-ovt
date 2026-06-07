#!/bin/bash
# Runs at atmoz/sftp container start (/etc/sftp.d/ hook) before sshd.
# Creates the rehearsal deployment directory layout inside the chroot home.
set -euo pipefail

DEPLOY_HOME=/home/deploy

# Create directory layout simulating the Ouvaton server home.
# /home/deploy is the SFTP chroot root (owned by root — SSH chroot requirement).
# Subdirectories are owned by the deploy user so lftp can write to them.
mkdir -p \
  "${DEPLOY_HOME}/staging" \
  "${DEPLOY_HOME}/releases" \
  "${DEPLOY_HOME}/shared/writable/cache" \
  "${DEPLOY_HOME}/shared/writable/logs" \
  "${DEPLOY_HOME}/shared/writable/session" \
  "${DEPLOY_HOME}/shared/writable/uploads" \
  "${DEPLOY_HOME}/httpdocs"

chown -R deploy:deploy \
  "${DEPLOY_HOME}/staging" \
  "${DEPLOY_HOME}/releases" \
  "${DEPLOY_HOME}/shared" \
  "${DEPLOY_HOME}/httpdocs"

chmod -R 777 \
  "${DEPLOY_HOME}/staging" \
  "${DEPLOY_HOME}/releases" \
  "${DEPLOY_HOME}/shared" \
  "${DEPLOY_HOME}/httpdocs"
