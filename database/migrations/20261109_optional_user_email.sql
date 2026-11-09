-- Permite múltiplos usuários sem e-mail, preservando o índice único.
ALTER TABLE usuarios MODIFY COLUMN email VARCHAR(100) NULL DEFAULT NULL;
UPDATE usuarios SET email = NULL WHERE email = '';
