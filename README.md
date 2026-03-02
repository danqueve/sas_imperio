# 💼 SAS Imperio — Sistema de Gestión de Créditos

Sistema web de gestión de créditos por artículos físicos con cobranza a domicilio.  
Stack: **PHP 8+ · MySQL 8+ · Bootstrap 5 · FPDF**

---

## ✨ Funcionalidades

- **Dashboard** con KPIs de cartera, cobros del día y ranking de cobradores
- **Clientes** — CRUD completo con sección de garante, coordenadas GPS y link WhatsApp
- **Artículos** — catálogo de productos con precios y stock
- **Créditos** — alta de crédito con calculador en tiempo real y generación automática de cuotas (semanal / quincenal / mensual)
- **Agenda del Cobrador** — vista diaria con mora calculada en tiempo real (2.5% por día hábil), modal de pago por efectivo/transferencia/mixto, links WhatsApp y Google Maps
- **Rendiciones** — flujo de aprobación: cobrador registra pago temporal → Admin/Supervisor aprueba → cuota confirmada
- **Gestión de Usuarios** — cobradores, supervisores y administradores con control de acceso por rol
- **PDF Cronograma** — impresión del plan de pagos por crédito (requiere FPDF)

---

## 🗂 Estructura

```
creditos/
├── index.php                   ← punto de entrada
├── sql/schema.sql              ← schema completo e instalación
├── config/
│   ├── conexion.php            ← (excluido del repo, ver .example)
│   ├── conexion.example.php    ← plantilla de configuración
│   ├── sesion.php              ← roles y permisos
│   └── funciones.php           ← mora, cuotas, helpers
├── assets/
│   ├── css/style.css           ← tema oscuro (dark mode)
│   └── js/app.js               ← calculador, toasts, modales
├── auth/                       ← login / logout
├── admin/                      ← dashboard, rendiciones, usuarios
├── clientes/                   ← CRUD completo
├── articulos/                  ← CRUD completo
├── creditos/                   ← listado, nuevo, ver, PDF
├── cobrador/                   ← agenda diaria + registrar pago
├── supervisor/                 ← rendiciones
└── views/                      ← layout reutilizable (sidebar + topbar)
```

---

## 🛠 Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/danqueve/sas_imperio.git
# Colocar la carpeta en: c:\wamp64\www\sistema\creditos\
```

### 2. Crear la base de datos

```bash
mysql -u root -e "CREATE DATABASE imperio_comercial CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci;"
mysql -u root imperio_comercial < sql/schema.sql
```

### 3. Configurar la conexión

```bash
cp config/conexion.example.php config/conexion.php
# Editar config/conexion.php con tus credenciales MySQL
```

### 4. Acceder al sistema

```
http://localhost/sistema/creditos/
```

| Usuario | Contraseña | Rol |
|---|---|---|
| `admin` | `password` | Administrador |

> ⚠️ **Cambiar la contraseña** desde el módulo de Usuarios en el primer acceso.

---

## 🔑 Roles y Permisos

| Acción | Admin | Supervisor | Cobrador |
|---|:---:|:---:|:---:|
| Ver/editar clientes | ✅ | ✅ | ❌ |
| Alta de créditos | ✅ | ✅ | ❌ |
| Ver agenda de cobros | ✅ | ✅ | ✅ (solo propios) |
| Registrar pagos | ✅ | ✅ | ✅ |
| Aprobar rendiciones | ✅ | ✅ | ❌ |
| Gestionar usuarios | ✅ | ❌ | ❌ |
| Dashboard y reportes | ✅ | ✅ | ❌ |

---

## 💡 Lógica de Negocio

- **Mora:** 15% semanal / 6 días hábiles = **2.5% por día hábil de atraso**
- **Cuotas automáticas:** semanal (7 días), quincenal (15 días), mensual
- **Flujo de rendición:** pago temporal → aprobación → pago confirmado + cuota actualizada

---

## 📦 Dependencias

- PHP 8.0+
- MySQL 8.0+
- [FPDF](http://www.fpdf.org/) — para generación de PDF de cronograma (colocar en `fpdf/` a nivel del directorio raíz)
- Font Awesome 6 (CDN)
- Google Fonts — Inter (CDN)

---

## 📄 Licencia

Proyecto privado — Imperio Comercial © 2026
