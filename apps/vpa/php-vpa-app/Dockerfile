FROM image-registry.openshift-image-registry.svc:5000/openshift/php:8.1-ubi9

LABEL maintainer="lab-vpa"
LABEL description="PHP app para estudo de VPA + k6 no OpenShift 4.19"

COPY index.php /var/www/html/

EXPOSE 8080

ENV POD_NAME=""
ENV POD_NAMESPACE=""
ENV NODE_NAME=""

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/html", "/var/www/html/index.php"]