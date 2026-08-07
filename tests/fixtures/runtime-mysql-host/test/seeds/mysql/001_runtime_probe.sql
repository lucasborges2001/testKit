CREATE TABLE IF NOT EXISTS testkit_runtime_probe (
    id INT NOT NULL PRIMARY KEY,
    marker VARCHAR(32) NOT NULL
);

INSERT INTO testkit_runtime_probe (id, marker)
VALUES (1, 'seeded')
ON DUPLICATE KEY UPDATE marker = VALUES(marker);
