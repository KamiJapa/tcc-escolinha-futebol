-- Migração incremental para os detalhes de planejamento dos treinos.
-- Execute uma única vez em cada banco que já utiliza o sistema.

ALTER TABLE TB_AULA
    ADD COLUMN HORARIO TIME NULL AFTER TEMA_TREINO,
    ADD COLUMN OBJETIVO TEXT NULL AFTER HORARIO,
    ADD COLUMN EXERCICIOS TEXT NULL AFTER OBJETIVO;
