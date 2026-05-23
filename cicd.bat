git fetch
git pull
git status
git add .
git commit -m "autocomit :: %date% %time% %computername%"
git push
timeout 2
ssh sync "cd /var/www/precios/ && git stash"
ssh sync "cd /var/www/precios/ && git pull"
::ssh sync "cd /var/www/precios/ && git pull origin evo"
