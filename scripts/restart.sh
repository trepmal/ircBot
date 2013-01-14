#!/bin/bash

#update automatically
git pull origin master

# kill any previously running bot processes
pids=$(ps aux | grep "/home/cdanley/ircBot/index.php" | grep -v grep | awk '{print $2}')
declare -a apids
apids=( ${pids} )
for (( i = 0; i < ${#apids[@]}; i += 1 ));
do
    kill ${apids[$i]}
done

# relaunch the ircBot script
nohup /usr/bin/php /srv/www/my.dev/ircBot/index.php &