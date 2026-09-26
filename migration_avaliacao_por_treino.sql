-- Migração incremental para avaliações vinculadas a cada treino.
-- Execute uma única vez em cada banco existente antes de publicar a funcionalidade.
-- Os campos nulos mantêm as aulas e avaliações antigas sem reescrever seu histórico.

ALTER TABLE TB_AULA
    ADD COLUMN TIPO_TREINO VARCHAR(30) NULL AFTER TEMA_TREINO,
    ADD COLUMN ATRIBUTOS_AVALIAVEIS_JSON JSON NULL AFTER TIPO_TREINO;

ALTER TABLE TB_AVALIACAO
    ADD COLUMN COD_AULA INT NULL AFTER COD_AVALIACAO,
    MODIFY COLUMN NOTA_GERAL DECIMAL(3,1) NULL,
    ADD UNIQUE KEY UX_TB_AVALIACAO_AULA_ALUNO (COD_AULA, COD_ALUNO),
    ADD CONSTRAINT FK_TB_AVALIACAO_AULA
        FOREIGN KEY (COD_AULA) REFERENCES TB_AULA (COD_AULA) ON DELETE SET NULL;
