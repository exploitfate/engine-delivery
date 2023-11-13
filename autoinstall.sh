#!/bin/bash

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BOLDRED='\033[0;91m'
BOLDGREEN='\033[0;92m'
NC='\033[0m' # No Color

## Get affiliate request settings

REQUEST_SCHEMA=""
REQUEST_SCHEMA_DEFAULT="http"

while [ -z "$REQUEST_SCHEMA" ]
do
  echo -n "Enter affiliate schema [ $REQUEST_SCHEMA_DEFAULT ]: "
  read REQUEST_SCHEMA
  if [ -z "$REQUEST_SCHEMA" ]; then
    REQUEST_SCHEMA=$REQUEST_SCHEMA_DEFAULT
  fi
done

REQUEST_HOST=""
REQUEST_HOST_DEFAULT="cnn.com"

while [ -z "$REQUEST_HOST" ]
do
  echo -n "Enter affiliate host [ $REQUEST_HOST_DEFAULT ]: "
  read REQUEST_HOST
  if [ -z "$REQUEST_HOST" ]; then
    REQUEST_HOST=$REQUEST_HOST_DEFAULT
  fi
done

REQUEST_PATH=""
REQUEST_PATH_DEFAULT="/hit"

while [ -z "$REQUEST_PATH" ]
do
  echo -n "Enter affiliate path [ $REQUEST_PATH_DEFAULT ]: "
  read REQUEST_PATH
  if [ -z "$REQUEST_PATH" ]; then
    REQUEST_PATH=$REQUEST_PATH_DEFAULT
  fi
done

SUPERSLACKER_WEBHOOK_URL=""
SUPERSLACKER_WEBHOOK_URL_EXAMPLE="https://hooks.slack.com/services/XXXXXXXXX/YYYYYYYY/aaBB33SSjjQQ99ff22GG22PP"

while [ -z "$SUPERSLACKER_WEBHOOK_URL" ]
do
  echo "Example: $SUPERSLACKER_WEBHOOK_URL_EXAMPLE"
  echo -n "Enter slack webhook url : "
  read SUPERSLACKER_WEBHOOK_URL
done

SUPERSLACKER_WEBHOOK_CHANNEL=""
SUPERSLACKER_WEBHOOK_CHANNEL_EXAMPLE="engine-delivery"

while [ -z "$SUPERSLACKER_WEBHOOK_CHANNEL" ]
do
  echo "Example: $SUPERSLACKER_WEBHOOK_CHANNEL_EXAMPLE"
  echo -n "Enter slack channel : "
  read SUPERSLACKER_WEBHOOK_CHANNEL
done

SUPERSLACKER_WEBHOOK_DOMAIN=""
SUPERSLACKER_WEBHOOK_DOMAIN_DEFAULT="$(hostname)"


while [ -z "$SUPERSLACKER_WEBHOOK_DOMAIN" ]
do
  echo -n "Enter affiliate host [ $SUPERSLACKER_WEBHOOK_DOMAIN_DEFAULT ]: "
  read SUPERSLACKER_WEBHOOK_DOMAIN
  if [ -z "$SUPERSLACKER_WEBHOOK_DOMAIN" ]; then
    SUPERSLACKER_WEBHOOK_DOMAIN=$SUPERSLACKER_WEBHOOK_DOMAIN_DEFAULT
  fi
done

echo "-------------------------------------------------------------------------------"
echo "Check taken credentials before continue"
echo "-------------------------------------------------------------------------------"

echo "Affiliate SCHEMA: $REQUEST_SCHEMA"
echo "Affiliate HOST: $REQUEST_HOST"
echo "Affiliate PATH: $REQUEST_PATH"
echo "Affiliate URL: $REQUEST_SCHEMA://$REQUEST_HOST$REQUEST_PATH"

echo "Slack WEBHOOK URL: $SUPERSLACKER_WEBHOOK_URL"
echo "Slack webhook CHANNEL: $SUPERSLACKER_WEBHOOK_CHANNEL"
echo "Slack monitoring DOMAIN: $SUPERSLACKER_WEBHOOK_DOMAIN"

CONTINUE_EXECUTE_DEFAULT="n"
echo -n "Continue? [y/N] :"
read CONTINUE_EXECUTE
CONTINUE_EXECUTE="$(tr '[:upper:]' '[:lower:]' <<< "$CONTINUE_EXECUTE")"

if [ -z "$CONTINUE_EXECUTE" ]; then
  CONTINUE_EXECUTE=$CONTINUE_EXECUTE_DEFAULT
fi

if [ "$CONTINUE_EXECUTE" = "n" ]; then
  echo ""
  echo -e "${BOLDRED}Interrupted${NC}"
  echo ""
  exit 0
fi


echo "-------------------------------------------------------------------------------"
echo "Install packages"
echo "-------------------------------------------------------------------------------"
sudo apt-get update
sudo apt-get install -y rabbitmq-server supervisor python-setuptools git ntp

sudo apt-get install -y php-bcmath php-mbstring

sudo easy_install pip
sudo pip install superslacker

echo "-------------------------------------------------------------------------------"
echo "Install composer"
echo "-------------------------------------------------------------------------------"

if [ -e /usr/local/bin/composer ]
  then
    COMPOSER_VERSION="$(composer --version)"
    echo ""
    echo -e "${BOLDGREEN}Found installed $COMPOSER_VERSION${NC}"
    echo ""
  else
    echo "${GREEN}Install composer${NC}"
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    sudo chmod 777 /usr/local/bin/composer
    COMPOSER_VERSION="$(composer --version)"
    echo "${GREEN}$COMPOSER_VERSION have been installed${NC}"
fi

echo "-------------------------------------------------------------------------------"
echo "Create project"
echo "-------------------------------------------------------------------------------"

if [ ! -d /var/engine ]
  then
    sudo mkdir -p /var/engine

    GITHUB_DEPLOY_KEY_DEFAULT="y"
    echo -n "Use github deploy key? [Y/n] :"
    read GITHUB_DEPLOY_KEY
    GITHUB_DEPLOY_KEY="$(tr '[:upper:]' '[:lower:]' <<< "$GITHUB_DEPLOY_KEY")"

    if [ -z "$GITHUB_DEPLOY_KEY" ];
      then
        GITHUB_DEPLOY_KEY=$GITHUB_DEPLOY_KEY_DEFAULT
    fi

    if [ "$GITHUB_DEPLOY_KEY" = "y" ];
      then
        sudo git clone git@github.com:exploitfate/engine-delivery.git /var/engine
      else
        sudo git clone https://github.com/exploitfate/engine-delivery.git /var/engine
    fi
  else
    if [ -f /var/engine/composer.lock ]
      then
        cd /var/engine
        sudo composer --no-dev install
      else
        sudo rm -rf /var/engine
        sudo mkdir -p /var/engine

        GITHUB_DEPLOY_KEY_DEFAULT="y"
        echo -n "Use github deploy key? [Y/n] :"
        read GITHUB_DEPLOY_KEY
        GITHUB_DEPLOY_KEY="$(tr '[:upper:]' '[:lower:]' <<< "$GITHUB_DEPLOY_KEY")"

        if [ -z "$GITHUB_DEPLOY_KEY" ];
          then
            GITHUB_DEPLOY_KEY=$GITHUB_DEPLOY_KEY_DEFAULT
        fi

        if [ "$GITHUB_DEPLOY_KEY" = "y" ];
          then
            sudo git clone git@github.com:exploitfate/engine-delivery.git /var/engine
          else
            sudo git clone https://github.com/exploitfate/engine-delivery.git /var/engine
        fi
    fi
fi

cd /var/engine
sudo composer install --no-dev
sudo composer dumpautoload -o

if [ -e /var/engine/config/config-local.php ]
then
    sudo cp /var/engine/config/config-local.php /var/engine/config/config-local.php.backup.$(date +%s)
fi

sudo cp /var/engine/config/config-local.php.dist /var/engine/config/config-local.php

sudo sed -i "s/'schema' => 'http',/'schema' => '"$REQUEST_SCHEMA"',/g" /var/engine/config/config-local.php
sudo sed -i "s/'host' => 'hostname.com',/'host' => '"$REQUEST_HOST"',/g" /var/engine/config/config-local.php
sudo sed -i "s#'path' => '/route/',#'path' => '"$REQUEST_PATH"',#g" /var/engine/config/config-local.php

echo '[program:engine-delivery]
command=/var/engine/delivery
startsecs=1
user=root
numprocs = 2
process_name = engine-delivery_%(process_num)s
autostart=true
autorestart=true
stopsignal=KILL
' | sudo tee /etc/supervisor/conf.d/engine-delivery.conf

echo '[program:failover-engine-delivery]
      command=/var/engine/delivery
      startsecs=1
      user=root
      numprocs = 2
      process_name = failover-engine-delivery_%(process_num)s
      autostart=true
      autorestart=true
      stopsignal=KILL
      startretries = 86400
' | sudo tee /etc/supervisor/conf.d/failover-engine-delivery.conf

echo '[eventlistener:superslacker]
command=superslacker --webhook="'$SUPERSLACKER_WEBHOOK_URL'" --channel="'$SUPERSLACKER_WEBHOOK_CHANNEL'" --hostname="'$SUPERSLACKER_WEBHOOK_DOMAIN'"
events=PROCESS_STATE,TICK_60
' | sudo tee /etc/supervisor/conf.d/superslacker.conf

if grep "$REQUEST_HOST" /etc/hosts > /dev/null
then
    echo ""
    echo -e "${BOLDGREEN}Found $REQUEST_HOST exists in /etc/hosts already${NC}"
    echo ""
else
    echo '
# affiliate host
255.255.255.255        '$REQUEST_HOST'
' | sudo tee -a /etc/hosts
fi

sudo supervisorctl update
sudo supervisorctl reload
sudo supervisorctl restart all

sudo supervisorctl status all

echo ""
echo -e "${BOLDGREEN}Congratulation. Installation has been completed successfully${NC}"
echo ""
