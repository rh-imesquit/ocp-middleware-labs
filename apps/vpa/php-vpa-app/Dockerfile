# ──────────────────────────────────────────────────────────────────
# Imagem base: Red Hat UBI 8 com PHP 8.1
# Compatível com OpenShift (non-root por padrão)
# ──────────────────────────────────────────────────────────────────
FROM image-registry.openshift-image-registry.svc:5000/openshift/php:8.1-ubi9

# Metadados
LABEL maintainer="lab-vpa"
LABEL description="PHP app para estudo de VPA + k6 no OpenShift 4.19"

# Copiar código-fonte
COPY src/ /var/www/html/

# O servidor web embutido do PHP escuta na porta 8080
# (porta não-privilegiada — compatível com SCC restricted do OCP)
EXPOSE 8080

# Variáveis que o OpenShift vai sobrescrever via downward API
ENV POD_NAME=""
ENV POD_NAMESPACE=""
ENV NODE_NAME=""

# php -S não precisa de root; compatível com anyuid e restricted SCC
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/html", "/var/www/html/index.php"]