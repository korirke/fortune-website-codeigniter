# Troubleshooting Guide

## 404 Error Fixes

### 1. Check Server is Running
```bash
php spark serve --host=localhost --port=8080
```

### 2. Test Basic Endpoint
Try accessing: `http://localhost:8080/test.php` (should work)

### 3. Check Routes
```bash
php spark routes
```

### 4. Common Issues Fixed:

#### ✅ Fixed: Default Controller
- Changed `Routing.php` default controller from `Home` to `App`
- Updated `Routes.php` to set default controller

#### ✅ Fixed: Index Page
- Changed `App.php` config: `$indexPage = ''` (removed index.php from URLs)

#### ✅ Fixed: Route Paths
- Removed leading slashes from route paths (except root)

### 5. Test These URLs:

**Should Work:**
- `http://localhost:8080/` - Root endpoint
- `http://localhost:8080/health` - Health check
- `http://localhost:8080/test.php` - Direct PHP test
- `http://localhost:8080/navigation` - Public endpoint
- `http://localhost:8080/hero` - Hero endpoint

**If Still Getting 404:**

1. **Check .env file exists:**
   ```bash
   copy env .env
   ```

2. **Check writable permissions:**
   ```bash
   # Make sure writable/ directory is writable
   ```

3. **Clear cache:**
   ```bash
   # Delete writable/cache/* (if exists)
   ```

4. **Check PHP errors:**
   - Look at `writable/logs/log-*.php` for errors

5. **Verify controller exists:**
   ```bash
   php -r "require 'vendor/autoload.php'; var_dump(class_exists('App\Controllers\App'));"
   ```

### 6. Alternative: Use index.php in URL

If clean URLs don't work, try:
- `http://localhost:8080/index.php/`
- `http://localhost:8080/index.php/health`
- `http://localhost:8080/index.php/navigation`

### 7. Check Server Logs

The server output should show:
```
CodeIgniter development server started on http://localhost:8080
```

If you see errors, check:
- PHP version (needs 8.1+)
- Composer dependencies installed
- Database connection (if using database)

### 8. Manual Route Test

Create a simple test route in `Routes.php`:
```php
$routes->get('test-route', function() {
    return json_encode(['success' => true, 'message' => 'Route works!']);
});
```

Then test: `http://localhost:8080/test-route`
