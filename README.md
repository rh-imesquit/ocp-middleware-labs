oc project apps

oc new-app https://github.com/rh-imesquit/ocp-middleware-labs \
  --context-dir=apps/vpa/php-vpa-app \
  --name=php-vpa-app \
  --strategy=docker