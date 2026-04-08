Archivos incluidos
- compose.yaml
- compose.mysql.yaml
- compose.redis.yaml
- bin/testkit
- bin/testkit.ps1
- .env.test.example
- test/.env.test

Uso esperado
1) Copiar los archivos de testkit en el repo de testkit.
2) En el proyecto Kiara, guardar test/.env.test con el contenido incluido.
3) Levantar:
   ./bin/testkit doctor --dump
   ./bin/testkit up -d
4) Correr:
   ./bin/testkit run --rm testkit php runTest.php back-php

Notas
- TESTKIT_STACK=mysql deja afuera redis y pg.
- Si un proyecto necesita redis:
  TESTKIT_STACK=mysql,redis
- Se mantiene compatibilidad hacia atrás: si TESTKIT_STACK no existe, el default es mysql,redis.
- El flag legacy --pg sigue funcionando y agrega pg al stack efectivo.
