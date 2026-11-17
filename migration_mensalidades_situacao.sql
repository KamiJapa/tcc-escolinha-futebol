-- Atualiza instalações que já possuem a tabela financeira.
-- Os lançamentos existentes mantêm a situação registrada.
ALTER TABLE TB_FINANCEIRO
    MODIFY COLUMN STATUS ENUM('PENDENTE','PAGO','CANCELADO','ATRASADO','NAO_PAGO') NOT NULL DEFAULT 'PENDENTE';
