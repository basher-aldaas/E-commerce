# E-Commerce & Warehouse System

نظام تجارة إلكترونية مبني باستخدام **Laravel**، مع خدمة مستقلة لإدارة المستودعات مبنية باستخدام **Python / Django + gRPC**.

يعمل المشروعان باستخدام Docker Compose، ولكل خدمة قاعدة بيانات MySQL مستقلة.

---

## 1. Project Architecture

يتكون النظام من مشروعين مستقلين داخل Repository واحد على GitHub، لكن كل مشروع موجود على Branch مختلف.

| Project | Branch | Technology |
|---|---|---|
| E-Commerce | `feature/secondRequirement` | Laravel 12 / PHP |
| Warehouse Service | `feature/laravel-integration` | Python / Django / gRPC |

### Folder Structure

يجب أن يكون ترتيب المجلدات بالشكل التالي:

```text
eco/
│
├── E-commerce/
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── .env
│   ├── docker/
│   └── Laravel files
│
└── warehouse-service/
    ├── Dockerfile
    ├── manage.py
    ├── requirements.txt
    └── Django / Python files
```

**Important:** يجب أن يكون المشروعان في نفس المجلد الأب حتى يستطيع Docker الوصول إلى Warehouse Service باستخدام المسار النسبي.

---

## 2. Requirements

قبل البدء، يجب تثبيت البرامج التالية:

- Git
- Docker Desktop
- Docker Compose (يأتي مع Docker Desktop)

بعد التثبيت، شغّل Docker Desktop وتأكد أن Docker يعمل.

للتحقق:

```powershell
docker --version
docker compose version
```

---

## 3. Clone Both Projects

افتح PowerShell ونفّذ الأوامر التالية:

### Step 1: Create the Main Folder

```powershell
mkdir "$HOME\Desktop\eco"
cd "$HOME\Desktop\eco"
```

### Step 2: Clone Laravel Project

```powershell
git clone -b feature/secondRequirement https://github.com/basher-aldaas/eco-requirement1.git E-commerce
```

### Step 3: Clone Warehouse Project

```powershell
git clone -b feature/laravel-integration https://github.com/basher-aldaas/eco-requirement1.git warehouse-service
```

الآن أصبح لديك المشروعان في مجلدين منفصلين.

---

## 4. Configure Laravel Environment

ادخل إلى مجلد Laravel:

```powershell
cd E-commerce
```

أنشئ ملف `.env` اعتماداً على الملف النموذجي:

```powershell
Copy-Item .env.example .env
```

افتح ملف `.env` وأضف السطر التالي:

```env
WAREHOUSE_SERVICE_PATH=../warehouse-service
```

هذا المسار يسمح لـ Docker Compose بالوصول إلى مشروع Warehouse أثناء بناء صورة Docker الخاصة به.

**Important:** لا تضع هذا المسار داخل Dockerfile. إنه متغير يستخدمه Docker Compose لتحديد مكان ملفات المشروع على جهازك.

تأكد أيضاً من إعدادات قاعدة البيانات واتصال gRPC الموجودة في `.env` وملف `docker-compose.yml`.

---

## 5. Build and Start Docker Containers

تأكد أنك داخل مجلد `E-commerce` وأن Docker Desktop يعمل.

نفّذ:

```powershell
docker compose up -d --build
```

يقوم هذا الأمر بـ:

1. بناء صورة Laravel.
2. بناء صورة Warehouse Service.
3. إنشاء حاوية MySQL الخاصة بـ Laravel.
4. إنشاء حاوية MySQL الخاصة بـ Warehouse.
5. إنشاء حاوية phpMyAdmin.
6. تشغيل الخدمات وفق ترتيب الاعتماد والـ Health Checks المحدد في Docker Compose.

**Note:** أول عملية Build قد تستغرق وقتاً أطول لأنها تحتاج إلى تنزيل الصور والحزم وبناء الخدمات.

---

## 6. Monitor Docker Containers

افتح Terminal جديداً، وادخل إلى مجلد `E-commerce` نفسه.

لمتابعة سجلات التشغيل:

```powershell
docker compose logs -f
```

لمتابعة Laravel فقط:

```powershell
docker compose logs -f app
```

لمتابعة Warehouse:

```powershell
docker compose logs -f warehouse
```

لمشاهدة جميع الحاويات:

```powershell
docker compose ps -a
```

للخروج من متابعة السجلات اضغط:

```text
CTRL + C
```

هذا لا يوقف الحاويات؛ يوقف متابعة السجلات فقط.

---

## 7. Generate Laravel Application Keys

بعد تشغيل حاوية Laravel، نفّذ الأوامر التالية لأول تشغيل فقط:

### Generate APP_KEY

```powershell
docker compose exec app php artisan key:generate
```

### Generate JWT Secret

```powershell
docker compose exec app php artisan jwt:secret
```

هذه المفاتيح خاصة ببيئة التشغيل الجديدة، ولا ينبغي مشاركة قيمها أو رفع ملف `.env` إلى GitHub.

بعد ذلك أعد تشغيل Laravel:

```powershell
docker compose restart app
```

---

## 8. Access the Applications

بعد نجاح تشغيل الحاويات، يمكنك الوصول إلى الخدمات من المتصفح:

| Service | URL |
|---|---|
| Laravel E-Commerce API | http://localhost:8000 |
| phpMyAdmin | http://localhost:8080 |

---

## 9. Database Configuration

يحتوي النظام على قاعدتي بيانات مستقلتين، وكل واحدة تعمل داخل MySQL Container منفصل.

### Database 1: Laravel MySQL

| Setting | Value |
|---|---|
| Container | `ecommerce-mysql` |
| Service | `mysql` |
| Database | `E-commerce` |
| Username | `root` |
| Password | Empty |
| Internal Port | `3306` |
| External Port | `3307` |

### Database 2: Warehouse MySQL

| Setting | Value |
|---|---|
| Container | `ecommerce-warehouse-mysql` |
| Service | `warehouse-mysql` |
| Database | `warehouse` |
| Username | `warehouse` |
| Password | `warehouse` |
| Internal Port | `3306` |
| External Port | Not exposed |

**Important:** قاعدة بيانات Warehouse غير مكشوفة مباشرة على جهاز المضيف؛ تتصل بها خدمة Warehouse عبر شبكة Docker الداخلية.

### Access Databases Through phpMyAdmin

افتح:

http://localhost:8080

ستجد خياري الاتصال:

**Laravel Database**

```text
Server: mysql
Username: root
Password: (leave empty)
```

**Warehouse Database**

```text
Server: warehouse-mysql
Username: warehouse
Password: warehouse
```

اختر السيرفر المناسب عند تسجيل الدخول.

---

## 10. How Laravel Communicates With Warehouse

تتواصل Laravel مع Warehouse باستخدام gRPC عبر شبكة Docker الداخلية.

```text
                 Client / Flutter
                        |
                        v
                  Laravel API
                   Port 8000
                        |
                        | gRPC
                        | warehouse:50051
                        v
                Warehouse Service
                  Python / gRPC
                        |
                        v
                 Warehouse MySQL
```

عنوان الاتصال داخل Docker:

```env
WAREHOUSE_GRPC_ENABLED=true
WAREHOUSE_GRPC_TARGET=warehouse:50051
```

**Important:**

- `warehouse` هو اسم الخدمة داخل Docker Compose.
- `50051` هو منفذ gRPC الداخلي.
- لا تحتاج إلى نشر منفذ gRPC على جهازك للوصول إليه من Laravel.
- لا تستخدم `localhost` بدلاً من `warehouse` داخل حاوية Laravel؛ لأن localhost يشير إلى حاوية Laravel نفسها.

---

## 11. Useful Docker Commands

يجب تنفيذ الأوامر التالية من مجلد `E-commerce` الذي يحتوي على `docker-compose.yml`.

### Start Containers

```powershell
docker compose up -d
```

### Stop Containers

```powershell
docker compose down
```

### Rebuild Images

```powershell
docker compose up -d --build
```

### View Running Containers

```powershell
docker compose ps
```

### View All Containers

```powershell
docker compose ps -a
```

### View Logs

```powershell
docker compose logs -f
```

### Restart Laravel

```powershell
docker compose restart app
```

### Enter Laravel Container

```powershell
docker compose exec app bash
```

### Enter Warehouse Container

```powershell
docker compose exec warehouse bash
```

### Stop and Remove Containers

```powershell
docker compose down
```

**Warning:** لا تستخدم الأمر التالي إلا إذا كنت تريد حذف بيانات قواعد البيانات نهائياً:

```powershell
docker compose down -v
```

لأنه يحذف Docker Volumes التي تحتوي بيانات MySQL.

---

## 12. Important Notes

1. لا ترفع ملف `.env` إلى GitHub.
2. لا ترفع كلمات المرور أو المفاتيح السرية إلى GitHub.
3. يجب تنزيل المشروعين من الفرعين الصحيحين.
4. يجب الحفاظ على أسماء المجلدات `E-commerce` و`warehouse-service` أو تحديث مسارات الإعدادات عند تغييرها.
5. يجب تشغيل Docker Desktop قبل تنفيذ أوامر Docker.
6. قواعد بيانات Laravel وWarehouse منفصلة تماماً من ناحية الحاويات والـ Volumes.
7. بيانات MySQL تبقى محفوظة حتى بعد إيقاف الحاويات أو حذفها باستخدام `docker compose down`، ما دامت الـ Volumes لم تُحذف.
8. لا تحتاج إلى تثبيت PHP أو Python أو MySQL محلياً لتشغيل الخدمات داخل Docker.

---

## 13. Complete Installation Workflow

يمكن تلخيص عملية تثبيت النظام على أي جهاز جديد بالترتيب التالي:

```text
1. Install Git
       |
       v
2. Install Docker Desktop
       |
       v
3. Start Docker Desktop
       |
       v
4. Create eco folder
       |
       v
5. Clone Laravel Branch
       |
       v
6. Clone Warehouse Branch
       |
       v
7. Create Laravel .env
       |
       v
8. Configure WAREHOUSE_SERVICE_PATH
       |
       v
9. docker compose up -d --build
       |
       v
10. Generate APP_KEY and JWT Secret
       |
       v
11. Restart Laravel
       |
       v
12. Access Laravel and phpMyAdmin
       |
       v
13. Test Laravel <-> Warehouse gRPC
```

**Result:** أصبح النظام جاهزاً للتشغيل باستخدام Docker، مع مشروع Laravel مستقل، وخدمة Warehouse مستقلة، وقاعدتي بيانات MySQL منفصلتين.
