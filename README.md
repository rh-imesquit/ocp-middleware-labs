oc project apps


oc new-app https://github.com/rh-imesquit/ocp-middleware-labs \
  --context-dir=apps/vpa/php-vpa-app \
  --name=php-vpa-app \
  --strategy=docker \
  --allow-missing-images


imesquit@imesquit-thinkpadx1carbongen11:~/Customers/PGERJ/OCP/ocp-middleware-labs$ oc get pods
NAME                  READY   STATUS    RESTARTS   AGE
php-vpa-app-1-build   1/1     Running   0          

