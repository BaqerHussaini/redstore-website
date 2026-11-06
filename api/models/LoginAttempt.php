<?php
declare(strict_types=1);

final class LoginAttempt
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{email: int, ip: int}
     */
    public function recentFailures(string $email, ?string $ip, int $windowSeconds): array
    {
        $statement = $this->db->prepare(
            'SELECT
                COUNT(*) FILTER (WHERE email = :email) AS email_count,
                COUNT(*) FILTER (WHERE ip_address = CAST(:ip AS inet)) AS ip_count
             FROM admin_login_attempts
             WHERE successful = false
                AND attempted_at > now() - make_interval(secs => :window)'
        );
        $statement->bindValue(':email', $email, PDO::PARAM_STR);
        if ($ip === null) {
            $statement->bindValue(':ip', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':ip', $ip, PDO::PARAM_STR);
        }
        $statement->bindValue(':window', max(1, $windowSeconds), PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch() ?: ['email_count' => 0, 'ip_count' => 0];

        return [
            'email' => (int) $row['email_count'],
            'ip' => (int) $row['ip_count'],
        ];
    }

    public function record(string $email, ?string $ip, ?string $userAgent, bool $successful): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO admin_login_attempts (email, ip_address, user_agent, successful)
             VALUES (:email, :ip, :user_agent, :successful)'
        );
        $statement->bindValue(':email', $email, PDO::PARAM_STR);
        if ($ip === null) {
            $statement->bindValue(':ip', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':ip', $ip, PDO::PARAM_STR);
        }
        $userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
        if ($userAgent === null || $userAgent === '') {
            $statement->bindValue(':user_agent', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':user_agent', $userAgent, PDO::PARAM_STR);
        }
        $statement->bindValue(':successful', $successful, PDO::PARAM_BOOL);
        $statement->execute();
    }

    public function clearFailures(string $email, ?string $ip): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM admin_login_attempts
             WHERE successful = false
                AND (email = :email OR (CAST(:ip AS inet) IS NOT NULL AND ip_address = CAST(:ip AS inet)))'
        );
        $statement->bindValue(':email', $email, PDO::PARAM_STR);
        if ($ip === null) {
            $statement->bindValue(':ip', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':ip', $ip, PDO::PARAM_STR);
        }
        $statement->execute();
    }

    public function prune(int $days = 30): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM admin_login_attempts WHERE attempted_at < now() - make_interval(days => :days)'
        );
        $statement->bindValue(':days', max(1, $days), PDO::PARAM_INT);
        $statement->execute();
    }
}
