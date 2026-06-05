USE barpos;

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_group VARCHAR(50) DEFAULT 'general',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES
('business_name',      'Bar & Restaurant',  'general'),
('business_address',   '',                  'general'),
('business_phone',     '',                  'general'),
('business_email',     '',                  'general'),
('currency',           'TZS',               'general'),
('currency_symbol',    'TZS',               'general'),
('timezone',           'Africa/Dar_es_Salaam', 'general'),
('low_stock_notify',   '1',                 'stock'),
('backup_auto',        '0',                 'system'),
('session_timeout',    '3600',              'security'),
('receipt_footer',     'Thank you for your business!', 'receipt');
