# Supervisor consumer for delivery visitor to affiliate

## Install and setup project

Install packages
```
sudo apt-get update
sudo apt-get install -y rabbitmq-server php-bcmath php-mbstring supervisor python-setuptools git ntp
```

Create project directory
```
sudo mkdir -p /var/engine
```

Install composer
```
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Clone project repo

```
git clone git@github.com:exploitfate/engine-delivery.git /var/engine
```

Install composer packages
```
cd /var/engine 
composer install
```

Optimize composer autoload
```
composer dumpautoload -o
```

Create local comfig and setup params
```
cp /var/engine/config/config-local.php.dist /var/engine/config/config-local.php
```

Test environment

```
/var/engine/delivery
```


## Install and setup supervisor

Create consumer config
```
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
```


Install [Superslacker](https://github.com/MTSolutions/superslacker)

```
sudo easy_install pip
sudo pip install superslacker
```

Create superslacker config (Don't forget to set `hostname`)

```
echo '[eventlistener:superslacker]
command=superslacker --webhook="https://hooks.slack.com/services/some/slack/token" --channel="soma-slack-channel" --hostname="landing.com"
events=PROCESS_STATE,TICK_60
' | sudo tee /etc/supervisor/conf.d/superslacker.conf
```

Update supervisor configuration
```
sudo supervisorctl update
sudo supervisorctl reload
```

Check workers status
```
sudo supervisorctl status all
```

Test notification

Put 
```
throw new \Exception('superslacker test');
```
in `Command::execute()` and wait for a minute


## FYI

On any update worker should be restarted
```
sudo supervisorctl restart all
```


## License

The MIT License (MIT). See [LICENSE](LICENSE) file.
