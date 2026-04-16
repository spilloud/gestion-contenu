SELECT 'CREATE DATABASE contenu'
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'contenu')\gexec
