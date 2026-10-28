=== Rocker in Love Contracts ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPLv2 or later

Contratos com assinatura eletrônica para a Rocker in Love.

== Como usar ==

1. Contratos → Configurações: confira quem contrassina (já vem com Daniel Correa e Grasiele Favoreto, Owner), o e-mail do estúdio, as instruções do Zelle e o link do PayPal.
2. Contratos → Novo contrato: nomes do casal, data, valores e detalhes. Clique em "Criar rascunho".
3. Revise o texto e clique em "Enviar para assinatura".
4. Copie o link (ou use o botão WhatsApp/E-mail) e mande para o casal.
5. O casal abre o link no celular, preenche, assina e vê o valor do sinal.
6. Quando o contrato aparecer como "Casal assinou", abra e contrassine: escolha quem assina com um clique (já vem marcado quem assinou da última vez naquele login), desenhe a assinatura e conclua. O PDF final é gerado e enviado por e-mail.
7. Quando o dinheiro cair, clique em "Sinal recebido".

== Editar ==

* Antes do casal assinar (rascunho ou já enviado): abra o contrato e clique em "Editar contrato". Mude valores, horas, fotógrafos ou o texto. O link do casal continua o mesmo e mostra a versão nova; dá para avisar o casal por e-mail.
* Se o casal estiver com a versão antiga aberta, o sistema não deixa assinar e mostra o texto novo antes.
* Depois que o casal assina, não dá para editar. Cancele e mande um novo, ou faça um aditivo.

== Listas de opções no modelo ==

* Em Modelos, um campo com opções é escrito assim: {{Coverage hours: 6 hours | 9 hours | 12 hours}}. No "Novo contrato" ele vira uma lista para escolher.

== Valores ==

* Total, Sinal (%) e Sinal (US$) andam juntos: mude a porcentagem e o sinal muda; digite o sinal e a porcentagem se ajusta; mude o total e o sinal segue a porcentagem. O padrão (40%) fica em Configurações.
* "Pago antes" (opcional): o que o casal já pagou antes do contrato. Entra no contrato como "Paid in advance" e é descontado do saldo.
* A cláusula 4 mostra sozinha o valor do sinal e a porcentagem.

== E-mails da jornada do casal ==

* Link perto de vencer: 3 dias antes do link vencer, se o casal ainda não assinou, ele recebe um lembrete para assinar. 2 dias antes (ou se o link já venceu), vocês recebem um aviso dizendo se o casal chegou a abrir o link.
* No contrato, "Renovar link" dá mais prazo sem mexer no texto e pode mandar o link de novo para o casal.
* Casal assina: recebe "Welcome to the family" com o sinal, Zelle e PayPal.
* Vocês contrassinam: recebe o contrato final com o PDF.
* Vocês marcam "Sinal recebido" ou "Pago total": recebe o recibo.
* 30 dias antes do casamento: cobrança do saldo. 7 dias antes, se ainda não pagou: lembrete.
* 2 dias depois do casamento: "Here's what happens next", o passo a passo de depois do casamento (edição, galeria em até 30 dias, download, álbum se o presente incluir, backup de 1 ano e o saldo, se ainda faltar pagar).
* Textos editáveis (e liga/desliga) em Configurações → E-mails da jornada do casal, com "Enviar teste para mim".
* No contrato, o quadro Pagamento mostra quando sai a próxima cobrança e tem "Enviar cobrança do saldo agora".

== Soft hold ==

* Se o casal ainda não sabe o dia, ele marca "We don't have the exact date yet — hold the month" e escolhe o mês e o ano. Horário e locais ficam opcionais (entram como "TBD").
* Contratos → Agenda: digite a data de um pedido de orçamento. Mostra se o dia está ocupado e quem está segurando aquele mês, com botões de WhatsApp e e-mail com a mensagem pronta.
* Quando o casal decidir, abra o contrato e use "Confirmar data". Fica registrado no histórico e o casal pode receber um e-mail.

== Atualizações automáticas ==

* Contratos → Configurações → "Atualizações automáticas (GitHub)": coloque o repositório (ex.: seu-usuario/ril-contracts) e, se ele for privado, a chave de acesso (só leitura).
* Quando sair uma versão nova, ela aparece na tela de Plugins como qualquer outro plugin. Clique em "Atualizar agora", ou ligue "Ativar atualizações automáticas" para ela se instalar sozinha.
* Atualizar não apaga contratos, modelos nem configurações.

== Detalhes ==

* Link do casal: seusite.com/contract/código-único/
* Quem pode usar o painel: administradores e editores do WordPress. Configurações (e-mails, pagamento e atualizações): só administradores.
* Os dados ficam no banco do próprio WordPress. Desativar ou apagar o plugin não apaga os contratos.
* Se o site usa plugin de cache, exclua o caminho /contract/ do cache.
* E-mails saem pelo WordPress. Se o site não envia e-mails bem, use um plugin de SMTP.

== Créditos ==

PDF gerado com tFPDF 1.33 (Ian Back, baseado no FPDF de Olivier Plathey), licença LGPL-2.1. Fontes Liberation (Red Hat), licença SIL Open Font License 1.1 em lib/tfpdf/font/unifont/Liberation_LICENSE.txt.
