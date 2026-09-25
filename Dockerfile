# =====================================================================
# Imagem do BACKEND — PHP 8.3 + Apache
# =====================================================================
FROM php:8.3-apache

# Extensões PHP:
#   pdo_mysql → conexão com o MySQL via PDO (prepared statements)
#   bcmath    → aritmética decimal exata para valores monetários
RUN docker-php-ext-install pdo_mysql bcmath

# A raiz pública do Apache passa a ser backend/public: config/ e src/
# ficam FORA do alcance do navegador (só o index.php é exposto).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# Roteamento: toda URL que não for um arquivo real vai para o index.php
# (front controller). Configurado direto no Apache em vez de .htaccess:
# não depende de arquivo oculto (que o Windows costuma renomear) e é mais
# rápido, pois o Apache não procura .htaccess a cada requisição.
# ServerTokens/ServerSignature: não revelam a versão do Apache (arquivo "zz-"
# para carregar DEPOIS do security.conf padrão, que definiria o contrário).
RUN { \
      echo '<Directory ${APACHE_DOCUMENT_ROOT}>'; \
      echo '    AllowOverride None'; \
      echo '    Require all granted'; \
      echo '    FallbackResource /index.php'; \
      echo '</Directory>'; \
      echo 'ServerTokens Prod'; \
      echo 'ServerSignature Off'; \
    } > /etc/apache2/conf-available/zz-app.conf \
 && a2enconf zz-app

# Configuração do PHP para ambiente de produção:
# erros vão para o log, nunca para a resposta da API (não vaza detalhes internos)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'date.timezone = America/Sao_Paulo'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'expose_php = Off'; \
      echo 'session.cookie_httponly = 1'; \
      echo 'session.use_strict_mode = 1'; \
      echo 'session.cookie_samesite = Strict'; \
    } > "$PHP_INI_DIR/conf.d/app.ini"

WORKDIR /var/www/html
COPY backend/ /var/www/html/

# O Apache roda com o usuário www-data
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80