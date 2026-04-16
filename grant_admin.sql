UPDATE "user"
SET roles = '["ROLE_ADMIN"]'::json
WHERE email = 'sylvain@osmose-marketing.ch';

SELECT id, email, roles
FROM "user"
WHERE email = 'sylvain@osmose-marketing.ch';

