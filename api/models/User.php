<?php
declare(strict_types=1);

final class User
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Returns the authentication row. Never send this array to a browser.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, email, password_hash, full_name, role, is_active, created_at, updated_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->bindValue(':email', trim($email), PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublicById(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, email, full_name, role, is_active, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAuthRowById(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, email, password_hash, full_name, role, is_active, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function updatePassword(string $id, string $passwordHash): bool
    {
        $statement = $this->db->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id AND role = \'admin\''
        );
        $statement->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    public function emailExists(string $email, ?string $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        $params = [':email' => trim($email)];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function updateProfile(string $id, string $email, string $fullName): bool
    {
        $statement = $this->db->prepare(
            'UPDATE users SET email = :email, full_name = :full_name
             WHERE id = :id AND role = \'admin\''
        );
        $statement->bindValue(':email', trim($email), PDO::PARAM_STR);
        $statement->bindValue(':full_name', trim($fullName), PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    /**
     * CLI-only helper. Callers must hash the password before calling this.
     */
    public function createAdmin(string $email, string $passwordHash, string $fullName): string
    {
        $statement = $this->db->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, is_active)
             VALUES (:email, :password_hash, :full_name, \'admin\', true)
             RETURNING id'
        );
        $statement->bindValue(':email', trim($email), PDO::PARAM_STR);
        $statement->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $statement->bindValue(':full_name', trim($fullName), PDO::PARAM_STR);
        $statement->execute();

        return (string) $statement->fetchColumn();
    }
}
