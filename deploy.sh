#!/bin/bash

# This is a generic deployment script copied into various projects.
# usage: ./deploy.sh <target> [target2 ...]
#   <target>  : "prod" , "test", "dev" or whatever

# It reads its setting from local, project unique, .deploy-config-<target>.sh files; typically not to be added to git.
# gitignore:  .deploy-config*.sh

# It syncs all of ./webroot content to the configured destination.
# Remote files are *not* deleted by default, delete manually if needed.

# Easiest way to get started is just to call it with a non-existing target;
# it will prompt for creating a corresponing prefilled template file to edit.

if [ "$#" -lt 1 ]; then
    echo "Usage: $0 <target> [target2 ...]"
    exit 1
fi

SRC_DIR="./webroot"

create_deploy_config_template() {
    local cfg_file="$1"
    cat > "$cfg_file" <<'DEPLOY_CONFIG_EOF'
#!/bin/bash
# Deployment configuration
# Choose one of the deploy types, local or ftp, and fill/remove as appropriate.
DEPLOY_LOC="http://example.com"          # URL of the deployed site

DEPLOY_TYPE="local"                      # for local filesystem transfer of files
DEPLOY_DST="/path/to/local/public_html"  # destination path on local filesystem

DEPLOY_TYPE="ftp"                        # for FTP transfer of files
DEPLOY_HOST="ftp.example.com"            # FTP server hostname
DEPLOY_USER="ftpuser"                    # FTP username
DEPLOY_PASS="ftppassword"                # FTP user's password
DEPLOY_DST="/path/to/remote/public_html" # destination path on the FTP server
DEPLOY_CONFIG_EOF
}

deploy_target() {
    local target="$1"
    local cfg_file=".deploy-config-${target}.sh"

    # If the config file does not exist, ask if one should be created
    if [ ! -f "$cfg_file" ]; then
        echo "No $cfg_file found for target '$target'. Do you want to create one? (y/n)"
        read answer
        if [ "$answer" == "y" ]; then
            create_deploy_config_template "$cfg_file"
            echo "$cfg_file file created. Please edit it with your deployment settings."
        else
            echo "Please create a $cfg_file file with your deployment settings."
        fi
        exit 1
    fi

    unset DEPLOY_LOC DEPLOY_TYPE DEPLOY_DST DEPLOY_HOST DEPLOY_USER DEPLOY_PASS
    source ./"$cfg_file"

    echo
    echo "Target location ($target): $DEPLOY_LOC"

    if [ "$DEPLOY_TYPE" == "local" ]; then
        echo "Deploying to local web root: $DEPLOY_DST"
        # rsync -av:
        #   -a preserves attributes and syncs directories recursively (archive mode)
        #   -v prints transferred files (verbose)
        rsync -av "$SRC_DIR"/ "$DEPLOY_DST"
    elif [ "$DEPLOY_TYPE" == "ftp" ]; then
        echo "Deploying to FTP server: $DEPLOY_HOST"

        # mirror -v -R = verbose reverse mirror:
        #    upload local SRC_DIR to remote DEPLOY_DST,
        #    adding/updating changed files on the server
        lftp -u "$DEPLOY_USER","$DEPLOY_PASS" "$DEPLOY_HOST" <<EOF
mirror -v -R "$SRC_DIR" "$DEPLOY_DST"
bye
EOF
    else
        echo "Unknown DEPLOY_TYPE: $DEPLOY_TYPE"
        echo "Fix your $cfg_file file."
        return 1
    fi
}

failed_targets=()
for target in "$@"; do
    if ! deploy_target "$target"; then
        failed_targets+=("$target")
    fi
done

if [ "${#failed_targets[@]}" -gt 0 ]; then
    echo
    echo "Deployment failed for target(s): ${failed_targets[*]}"
    exit 1
fi

echo
echo "Deployment completed successfully for target(s): $*"

