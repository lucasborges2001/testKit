# Quickstart — Docker (Windows / PowerShell)

## 0) Pre-requisitos

- Docker Desktop (encendido)
- PowerShell

## 1) Preparar env

Desde el **root del repo**:

```powershell
Copy-Item .\test\.env.test.example .\test\.env.test
```

Editar `test\.env.test`.

> Alternativa soportada: crear `root\.env.test`.

## 2) Levantar stack + seeds + tests

```powershell
cd .\test
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\scripts\seed.ps1
.\bin\testkit.ps1 run --rm testkit php runTest.php
```

(Wrapper opcional):

```powershell
.\scripts\test.ps1
.\scripts\test.ps1 back
```

## 3) Postgres (opcional)

```powershell
cd .\test
.\bin\testkit.ps1 --pg up -d
.\scripts\seed.ps1
.\bin\testkit.ps1 run --rm testkit php runTest.php
```

## 4) Apagar / reset

```powershell
cd .\test
.\bin\testkit.ps1 down
# reset total (borra volúmenes)
.\scripts\db_reset.ps1
```
