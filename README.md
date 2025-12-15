# Fortune Technologies CodeIgniter Backend

This is a complete CodeIgniter 4 backend API that mirrors the Node.js/NestJS backend structure, with all models, controllers, and endpoints matching the original implementation.

## Features

- ✅ **60+ Models** - All models from Prisma schema with exact field names and capitalization preserved
- ✅ **20+ Controllers** - All controllers matching Node.js endpoint structure
- ✅ **100+ Endpoints** - Complete API with Swagger/OpenAPI documentation
- ✅ **JWT Authentication** - Firebase JWT implementation
- ✅ **Role-Based Access Control** - User roles and permissions
- ✅ **Swagger Documentation** - Complete API documentation

## Installation

1. Install dependencies:
```bash
composer install
```

2. Copy environment file:
```bash
cp env .env
```

3. Configure database in `.env`:
```env
database.default.hostname = localhost
database.default.database = fortune_technologies
database.default.username = root
database.default.password = 
```

4. Run migrations (when created):
```bash
php spark migrate
```

## Project Structure

```
app/
├── Controllers/
│   ├── Auth/          # Authentication endpoints
│   ├── Public/        # Public website content
│   ├── Admin/         # Admin CMS endpoints
│   ├── Candidate/     # Candidate profile management
│   ├── Jobs/          # Job management
│   ├── Applications/  # Application management
│   ├── Companies/     # Company management
│   ├── Contact/       # Contact form
│   ├── Faq/           # FAQ management
│   ├── Upload/        # File uploads
│   ├── Users/         # User management
│   ├── AuditLog/      # Audit logs
│   ├── AdminCandidate/ # Admin candidate management
│   ├── RecruitmentAdmin/ # Recruitment admin
│   ├── PricingRequest/ # Quote requests
│   ├── About/         # About page admin
│   └── Swagger/       # Swagger documentation
├── Models/            # 60+ models from Prisma schema
├── Filters/           # Authentication filters
└── Config/
    ├── Routes.php     # All API routes
    └── Filters.php    # Filter configuration
```

## API Endpoints

All endpoints match the Node.js backend structure:

### Authentication
- `POST /auth/register` - User registration
- `POST /auth/login` - User login
- `POST /auth/verify-email` - Email verification
- `POST /auth/forgot-password` - Password reset request
- `POST /auth/reset-password` - Password reset
- `GET /auth/me` - Get current user

### Public Endpoints
- `GET /navigation` - Website navigation
- `GET /hero` - Hero section data
- `GET /services` - Services listing
- `GET /testimonials` - Testimonials
- `GET /footer` - Footer content
- And many more...

### Admin Endpoints
- `PUT /admin/navigation` - Update navigation
- `PUT /admin/theme` - Update theme
- `PUT /admin/services` - Update services
- And all CMS management endpoints...

### Candidate Endpoints
- `GET /candidate/profile` - Get profile
- `PUT /candidate/profile` - Update profile
- `POST /candidate/skills` - Add skill
- `POST /candidate/education` - Add education
- And all profile management endpoints...

### Jobs & Applications
- `POST /jobs` - Create job
- `GET /jobs/search` - Search jobs
- `POST /candidate/applications/apply` - Apply to job
- `GET /applications/job/{jobId}` - Get applications
- And all job/application management endpoints...

## Swagger Documentation

Access Swagger UI at:
```
http://localhost:8080/swagger
```

Get OpenAPI JSON at:
```
http://localhost:8080/swagger.json
```

## Models

All models maintain exact field names and capitalization from Prisma schema:
- `User`, `CandidateProfile`, `Company`, `Job`, `Application`
- `Skill`, `Domain`, `Education`, `Experience`
- `Service`, `Testimonial`, `Stat`, `Client`
- And 50+ more models...

## Authentication

JWT tokens are required for protected endpoints. Include in header:
```
Authorization: Bearer <token>
```

## Database

The database structure matches the Prisma schema exactly. Field names like `createdAt`, `updatedAt`, `firstName`, `lastName` are preserved as-is.

## Development

Run the development server:
```bash
php spark serve
```

## Notes

- All field names maintain exact capitalization from Prisma schema
- All endpoints match Node.js backend structure
- Swagger annotations included for all endpoints
- JWT authentication implemented
- Role-based access control ready
