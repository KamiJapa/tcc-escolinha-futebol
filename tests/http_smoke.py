"""Teste destrutivo somente em banco DEMO descartável. Requer TEST_BASE_URL.
Usa apenas a biblioteca padrão do Python. Execute após importar database.sql.
"""
import os, re, urllib.request, urllib.parse, urllib.error, http.cookiejar
from datetime import date, timedelta
BASE=os.environ.get('TEST_BASE_URL')
if not BASE: raise SystemExit('Defina TEST_BASE_URL para um servidor com banco descartável.')
TODAY=date.today().isoformat()
MONTH=TODAY[:7]
TRAINING_DATE=(date.today() + timedelta(days=(1-date.today().weekday()) % 7)).isoformat()
count=0
def ok(value,label):
 global count
 assert value,label
 count+=1;print('OK:',label,flush=True)
class Client:
 def __init__(self): self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(self,path,data=None):
  try:
   r=self.op.open(BASE+'/'+path,urllib.parse.urlencode(data).encode() if data is not None else None)
  except urllib.error.HTTPError as e:r=e
  body=r.read().decode(); code=r.status
  assert not re.search(r'(Fatal error|Warning:|Uncaught|Parse error)',re.sub('<[^>]+>', '', body)),body[:1200]
  return code,body
 def token(self,path):
  code,body=self.req(path);assert code==200,(path,code)
  return re.search(r'name="csrf_token" value="([^"]+)"',body)[1]
 def post(self,path,data):return self.req(path,dict(data,csrf_token=self.token(path)))
 def login(self,email):
  code,body=self.post('login.php',{'email':email,'senha':'123456'})
  ok(code==200 and 'E-mail ou senha inválidos' not in body and 'name="senha"' not in body,'Login '+email)
  return self
admin=Client().login('admin@teste.com')
for page in ['dashboard.php','alunos.php','alunos.php?mini=1','responsaveis.php','turmas.php','matriculas.php','aulas.php','aulas.php?chamada=1','avaliacoes.php','avaliacoes.php?aula=1','calendario.php','jogos.php','camisas.php','metas.php','notificacoes.php','usuarios.php','financeiro.php']:
 ok(admin.req(page)[0]==200,'GET '+page)
for page in ['calendario.php?mes%5B%5D=x&tipo%5B%5D=x&turma%5B%5D=x','notificacoes.php?filtro%5B%5D=x&pagina%5B%5D=x']:
 ok(admin.req(page)[0]==200,'GET array safe defaults '+page.split('?')[0])
# CSRF vazio e adulterado.
ok(Client().req('login.php',{'email':'admin@teste.com','senha':'123456'})[0]==400,'CSRF ausente rejeitado mesmo em sessão nova')
ok(admin.req('alunos.php',{'action':'delete','id':'1','csrf_token':'invalid'})[0]==400,'CSRF inválido não exclui aluno')
logout_client = Client().login('admin@teste.com')
code, body = logout_client.req('logout.php')
ok(code == 200 and 'name="senha"' not in body, 'Logout por GET redireciona com a sessão preservada')
ok(logout_client.req('logout.php', {})[0] == 400, 'Logout sem CSRF rejeitado')
ok(logout_client.req('logout.php', {'csrf_token': 'invalid'})[0] == 400, 'Logout com CSRF inválido rejeitado')
code, body = logout_client.req('dashboard.php')
logout_form = re.search(r'<form [^>]*method="post" action="logout.php">\s*<input [^>]*name="csrf_token" value="([^"]+)"', body)
ok(code == 200 and logout_form, 'Sessão preservada e formulário de saída disponível')
code, body = logout_client.req('logout.php', {'csrf_token': logout_form[1]})
ok(code == 200 and 'name="senha"' in body, 'Logout com CSRF válido retorna ao login')
ok('name="senha"' in logout_client.req('dashboard.php')[1], 'Logout encerra acesso à área autenticada')
# Matrícula e validação do servidor.
code,body=admin.post('matriculas.php',{'action':'create','cod_aluno':1,'cod_turma':2,'data_matricula':'2026-02-30','status':'ATIVA'})
ok('Dados da matrícula inválidos' in body,'Data impossível rejeitada')
code,body=admin.post('matriculas.php',{'action':'create','cod_aluno':1,'cod_turma':2,'data_matricula':TODAY,'status':'ATIVA'})
ok('Matrícula salva' in body,'Nova matrícula gravada')
code,body=admin.post('matriculas.php',{'action':'create','cod_aluno':1,'cod_turma':2,'data_matricula':TODAY,'status':'ATIVA'})
ok('já tem matrícula' in body,'Matrícula duplicada rejeitada')
# Chamada só aceita alunos da turma.
code,body=admin.post('aulas.php',{'action':'attendance','id':1,'alunos[4][presente]':1})
ok(code==400,'Chamada rejeita aluno de outra turma')
code,body=admin.post('aulas.php',{'action':'attendance','id':1,'alunos[1][presente]':1,'alunos[5][justificativa]':'Consulta'})
ok(code==200 and 'Chamada registrada' in body,'Chamada válida salva')
code,body=admin.post('aulas.php',{'action':'create','cod_turma':1,'data_aula':TRAINING_DATE,'tema_treino':'QA Passe','horario':'14:00','tipo_treino':'PASSE'})
ok('Treino cadastrado' in body,'Plano de treino cadastrado')
code,body=admin.post('avaliacoes.php?aula=1',{'action':'save_evaluations','id_aula':1,'tipo_treino':'PASSE','notas[1][passe]':'8.5','notas[5][passe]':'7'})
ok(code==200 and '8.5' in body,'Avaliação do treino salva')
# Financeiro com duplicidade e transições.
new={'acao':'criar','tipo':'RECEITA','categoria':'MENSALIDADE','descricao':'QA Mensalidade','valor':'120.50','aluno':1,'competencia':MONTH,'vencimento':TODAY}
code,body=admin.post('financeiro.php',new)
ok('Lançamento registrado' in body,'Mensalidade registrada')
code,body=admin.post('financeiro.php',new)
ok('já existe' in body,'Mensalidade duplicada rejeitada')
code,body=admin.req('financeiro.php?mes='+MONTH)
id=re.search(r'name="id" value="([1-9]\d*)"',body)[1]
code,body=admin.post('financeiro.php',{'acao':'pagar','id':id,'data_pagamento':TODAY})
ok('Lançamento atualizado' in body,'Baixa registrada')
code,body=admin.req('financeiro.php?mes='+MONTH)
ok('R$ 120,50' in body and 'Baixa: '+date.today().strftime('%d/%m/%Y') in body,'Total e data da baixa persistidos')
code,body=admin.post('financeiro.php',{'acao':'reabrir','id':id})
ok('Lançamento atualizado' in body,'Baixa desfeita')
code,body=admin.req('financeiro.php?editar='+id)
ok('QA Mensalidade' in body and 'Salvar alterações' in body,'Edição financeira carrega os dados')
code,body=admin.post('financeiro.php',dict(new,acao='editar',id=id,descricao='QA Mensalidade revisada'))
ok('Lançamento atualizado' in body,'Lançamento pendente editado')
code,body=admin.post('financeiro.php',dict(new,valor='-1'))
ok('Confira descrição' in body,'Valor negativo rejeitado')
code,body=admin.post('financeiro.php',{'acao':'cancelar','id':id})
ok('Lançamento atualizado' in body,'Mensalidade cancelada')
code,body=admin.req('financeiro.php?mes='+MONTH)
ok('Reabrir lançamento' in body,'Ação de reabertura de cancelado disponível')
code,body=admin.post('financeiro.php',{'acao':'reabrir','cancelado':'1','id':id})
ok('Lançamento atualizado' in body,'Mensalidade cancelada reaberta')
code,body=admin.req('financeiro.php?mes='+MONTH)
ok('PENDENTE' in body,'Mensalidade reaberta volta a pendente')
# Proteção de administrador e responsáveis.
code,body=admin.post('usuarios.php',{'action':'update','id':1,'nome':'Carlos','email':'admin@teste.com','perfil':'PROFESSOR','ativo':1,'senha':''})
ok('Mantenha seu próprio' in body,'Administrador não perde seu próprio acesso')
parent=Client().login('responsavel@teste.com')
code,body=parent.req('area_responsavel.php')
ok('Lucas' in body and 'Gabriel' in body,'Responsável vê filhos vinculados')
for page in ['financeiro.php','alunos.php','usuarios.php','avaliacoes.php']:
 ok(parent.req(page)[0]==403,'Responsável bloqueado em '+page)
coach=Client().login('professor@teste.com')
ok(coach.req('financeiro.php')[0]==403,'Professor sem acesso financeiro')
ok(coach.req('avaliacoes.php')[0]==200,'Professor acessa avaliações')
secretary=Client().login('secretaria@teste.com')
ok(secretary.req('financeiro.php')[0]==200,'Secretaria acessa financeiro')
ok(secretary.req('usuarios.php')[0]==403,'Secretaria não gerencia usuários')
# Conta do aluno criada pelo fluxo oficial.
code,body=admin.post('alunos.php',{'action':'update','id':1,'nome':'Lucas Oliveira (Filho do Fernando)','data_nascimento':'2016-04-15','cod_responsavel':1,'posicao':'Atacante','status':'ATIVO','email_acesso':'aluno@example.test','senha_acesso':'123456','cod_turma':2})
ok('Dados do aluno atualizados' in body,'Acesso do aluno criado')
student=Client().login('aluno@example.test')
for page in ['meus_alunos.php','evolucao.php','metas.php','jogos.php','calendario.php']:
 ok(student.req(page)[0]==200,'Aluno acessa '+page)
ok(student.req('evolucao.php?aluno%5B%5D=x&semana%5B%5D=x')[0]==200,'Evolução trata parâmetros GET em array')
ok(student.req('financeiro.php')[0]==403,'Aluno sem acesso financeiro')
# Jogos, camisas, metas e cadastro público de responsável.
code,body=admin.post('camisas.php',{'acao':'atribuir','aluno':1,'numero':18})
ok('Número de camisa atualizado' in body,'Camisa atribuída')
code,body=admin.post('camisas.php',{'acao':'atribuir','aluno':2,'numero':18})
ok('já está com' in body,'Camisa duplicada rejeitada')
code,body=admin.post('camisas.php',{'acao':'trocar','primeiro_aluno':1,'segundo_aluno':2})
ok('foram trocados' in body,'Troca de camisas salva')
game={'acao':'salvar','turma':1,'adversario':'QA Visitante','data':TODAY,'horario':'14:00','local':'Campo','gols_escolinha':2,'gols_adversario':1,'atletas[5][participou]':1,'atletas[5][titular]':1,'atletas[5][gols]':2,'atletas[5][assistencias]':0}
code,body=admin.post('jogos.php?novo=1',game)
ok('Jogo cadastrado' in body,'Jogo com escalação e estatísticas salvo')
code,body=admin.post('jogos.php?novo=1',dict(game,gols_escolinha=1))
ok('não podem superar' in body,'Súmula incoerente rejeitada')
code,body=student.post('metas.php',{'acao':'criar','tipo':'GOLS','valor_alvo':3})
ok('Meta criada' in body,'Meta pessoal salva')
code,body=student.post('metas.php',{'acao':'criar','tipo':'OVERALL','valor_alvo':11})
ok('objetivo válido' in body,'Meta fora da escala rejeitada')
ok(Client().req('cadastro.php', {})[0]==405,'Cadastro público desativado')
code,body=admin.post('responsaveis.php',{'action':'create','nome':'QA Responsável','cpf':'12345679204','email':'qa-parent@example.test','telefone':'','senha':'123456'})
ok('Responsável cadastrado' in body,'Conta do responsável criada pelo administrador')
newparent=Client().login('qa-parent@example.test')
code,body=newparent.req('area_responsavel.php')
ok('Lucas' not in body,'Nova conta não recebe filhos de terceiros')
code,body=admin.post('responsaveis.php',{'action':'create','nome':'QA Familiar','cpf':'12345679387','email':'','telefone':'','endereco':''})
ok('Responsável cadastrado' in body,'Responsável administrativo cadastrado')
code,body=admin.post('responsaveis.php',{'action':'create','nome':'QA CPF inválido','cpf':'11111111111','email':'','telefone':'','endereco':''})
ok('CPF válido' in body,'CPF repetido rejeitado')
# Conta revogada deve perder a sessão já aberta.
code,body=admin.post('usuarios.php',{'action':'update','id':3,'nome':'Professor','email':'professor@teste.com','perfil':'PROFESSOR','senha':''})
ok('Usuário atualizado' in body,'Professor desativado')
code,body=coach.req('aulas.php')
ok('name="senha"' in body,'Sessão de conta desativada revogada')
# E1/E6: aluno inativo perde acesso e matrículas ativas são encerradas.
student=Client().login('aluno@example.test')
code,body=admin.post('alunos.php',{'action':'update','id':1,'nome':'Lucas Oliveira (Filho do Fernando)','data_nascimento':'2016-04-15','cod_responsavel':1,'posicao':'Atacante','status':'INATIVO'})
ok('Dados do aluno atualizados' in body,'Aluno inativado sem reenviar e-mail')
ok('name="senha"' in student.req('meus_alunos.php')[1],'Sessão do aluno inativo revogada')
code,body=Client().post('login.php',{'email':'aluno@example.test','senha':'123456'})
ok('E-mail ou senha inválidos' in body,'Login de aluno inativo bloqueado')
# E3: excluir aula via HTTP e verificar a mensagem sobre as presenças.
code,body=admin.post('aulas.php',{'action':'delete','id':1})
ok('Aula excluída. As presenças foram removidas' in body,'Exclusão HTTP de aula informa remoção das presenças')
# Camisa opcional, observações vazias e duplicata entre alunos ativos.
new_student = {'action': 'create', 'nome': 'QA Camisa', 'data_nascimento': f'{date.today().year-11}-01-01', 'cod_turma': 2,
               'cod_responsavel': 1, 'status': 'ATIVO', 'observacoes_medicas': '',
               'numero_camisa': 98}
code, body = admin.post('alunos.php', new_student)
ok('Aluno cadastrado' in body, 'Aluno com camisa e observações vazias gravado')
code, body = admin.post('alunos.php', dict(new_student, nome='QA Duplicata'))
ok('já está com outro aluno ativo' in body, 'Cadastro rejeita camisa ativa duplicada')
code, body = admin.post('alunos.php', dict(new_student, nome='QA Sem Camisa', numero_camisa=''))
ok('Aluno cadastrado' in body, 'Cadastro aceita camisa vazia')

# Sessões separadas continuam usando o mesmo contador (deixe por último).
for attempt in range(3):
    login_email = ' ADMIN@TESTE.COM ' if attempt == 1 else 'admin@teste.com'
    code, body = Client().post('login.php', {'email': login_email, 'senha': 'invalida'})
    ok(code == 200 and 'E-mail ou senha inválidos.' in body, 'Falha de login em sessão nova')
code, body = Client().post('login.php', {'email': 'sem-conta@example.test', 'senha': 'invalida'})
ok(code == 200 and 'E-mail ou senha inválidos.' in body, 'Conta inexistente recebe a mesma mensagem')
code, body = Client().post('login.php', {'email': 'admin@teste.com', 'senha': '123456'})
ok(code == 200 and 'E-mail ou senha inválidos.' in body, 'Três falhas bloqueiam a senha correta')
print(f'{count} verificações passaram.')
