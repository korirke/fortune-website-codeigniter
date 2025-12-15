# Quick Deployment Checklist

Use this checklist during deployment to ensure nothing is missed.

## Pre-Deployment

- [ ] Code reviewed and tested
- [ ] All debug code removed
- [ ] Dependencies installed: `composer install --no-dev --optimize-autoloader`
- [ ] Database backup created (if migrating)
- [ ] Production `.env` file prepared
- [ ] SSL certificate ready

## File Upload

- [ ] All files uploaded to server
- [ ] Directory structure maintained
- [ ] `.env` file created (not uploaded from local)
- [ ] `writable/` directory exists

## Configuration

- [ ] `.env` file configured with production values
- [ ] Base URL set correctly
- [ ] Database credentials configured
- [ ] Email settings configured
- [ ] JWT secret generated
- [ ] Encryption key generated: `php spark key:generate`
- [ ] `CI_ENVIRONMENT = production` set

## Database

- [ ] Database created in cPanel
- [ ] Database user created with proper permissions
- [ ] Database schema imported
- [ ] Initial data seeded (if needed)
- [ ] Database connection tested

## File Permissions

- [ ] `writable/` directory: `chmod 775`
- [ ] `.env` file: `chmod 600`
- [ ] All directories: `chmod 755`
- [ ] All files: `chmod 644`
- [ ] Ownership set correctly

## Security

- [ ] `.htaccess` files in place
- [ ] Sensitive files protected
- [ ] CSRF protection enabled
- [ ] Error display disabled
- [ ] SSL/HTTPS configured
- [ ] Force HTTPS enabled

## Testing

- [ ] API endpoints accessible
- [ ] Authentication working
- [ ] Database queries working
- [ ] Email sending working
- [ ] File uploads working (if applicable)
- [ ] Sessions working
- [ ] CORS configured (if needed)

## Performance

- [ ] Opcode caching enabled
- [ ] Gzip compression enabled
- [ ] Browser caching configured
- [ ] Database indexes optimized

## Monitoring

- [ ] Error logging enabled
- [ ] Log files accessible
- [ ] Backup strategy configured
- [ ] Monitoring tools set up

## Documentation

- [ ] Deployment documentation reviewed
- [ ] Team notified of deployment
- [ ] Access credentials documented securely

## Post-Deployment

- [ ] All endpoints tested
- [ ] Error logs checked
- [ ] Performance verified
- [ ] Security scan completed
- [ ] Backup verified
- [ ] Team notified of successful deployment

---

## Emergency Rollback

If deployment fails:

1. **Restore Backup**
   ```bash
   # Restore database
   mysql -u user -p database < backup.sql
   
   # Restore files
   tar -xzf backup.tar.gz
   ```

2. **Revert Code**
   ```bash
   git checkout previous-stable-tag
   composer install --no-dev
   ```

3. **Check Logs**
   ```bash
   tail -f writable/logs/log-$(date +%Y-%m-%d).log
   ```

---

## Quick Fixes

### 500 Error
```bash
chmod -R 775 writable/
chmod 600 .env
```

### Database Error
- Verify credentials in `.env`
- Check database exists
- Verify user permissions

### Route Not Found
- Check `.htaccess` in `public/`
- Verify `mod_rewrite` enabled
- Check base URL in `.env`

### Permission Denied
```bash
chown -R user:user writable/
chmod -R 775 writable/
```

---

**Keep this checklist handy during deployment!**
