// Iniciador do Painel Matrícula para Windows.
//
// Sobe o servidor do painel dentro da WSL (php artisan serve), espera ele
// responder e abre o painel numa janela própria do Chrome (modo app).
// Fica na bandeja do sistema com as opções de abrir, reiniciar e encerrar.
//
// Compilado com o csc.exe que vem com o .NET Framework do Windows (C# 5):
// sem interpolação de strings, sem "?.", sem membros "=>". Veja compilar.sh.

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Windows.Forms;

[assembly: System.Reflection.AssemblyTitle("Painel Matrícula")]
[assembly: System.Reflection.AssemblyProduct("Painel Matrícula")]
[assembly: System.Reflection.AssemblyDescription("Inicia o Painel Matrícula na WSL e abre a janela do painel")]
[assembly: System.Reflection.AssemblyVersion("1.0.0.0")]

namespace PainelMatricula
{
    internal sealed class Configuracao
    {
        public string Distro = "Ubuntu";
        public string Pasta = "/home/werik/development/painel-matricula";
        public int Porta = 8010;
        public string Navegador = "chrome"; // chrome | edge | padrao

        // A janela abre em "localhost" (mesma origem de quem já usa o painel no
        // navegador: tema e menu ficam salvos por origem).
        public string Url { get { return "http://localhost:" + Porta; } }

        // A checagem usa 127.0.0.1: no Windows "localhost" tenta antes o IPv6 (::1),
        // que a WSL não encaminha, e só cai no IPv4 depois de ~2 s.
        public string UrlInterna { get { return "http://127.0.0.1:" + Porta; } }

        public static string Arquivo
        {
            get { return Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "PainelMatricula.ini"); }
        }

        public static Configuracao Carregar()
        {
            var c = new Configuracao();
            if (!File.Exists(Arquivo))
            {
                c.Salvar();
                return c;
            }

            foreach (var bruta in File.ReadAllLines(Arquivo, Encoding.UTF8))
            {
                var linha = bruta.Trim();
                if (linha.Length == 0 || linha.StartsWith(";") || linha.StartsWith("#") || linha.StartsWith("[")) continue;
                var igual = linha.IndexOf('=');
                if (igual <= 0) continue;
                var chave = linha.Substring(0, igual).Trim().ToLowerInvariant();
                var valor = linha.Substring(igual + 1).Trim();
                int porta;
                switch (chave)
                {
                    case "distro": if (valor.Length > 0) c.Distro = valor; break;
                    case "pasta": if (valor.Length > 0) c.Pasta = valor; break;
                    case "porta": if (int.TryParse(valor, out porta) && porta > 0) c.Porta = porta; break;
                    case "navegador": if (valor.Length > 0) c.Navegador = valor.ToLowerInvariant(); break;
                }
            }
            return c;
        }

        public void Salvar()
        {
            var texto = new StringBuilder();
            texto.AppendLine("; Configuração do iniciador do Painel Matrícula");
            texto.AppendLine("[painel]");
            texto.AppendLine("; Distro da WSL e pasta do painel (caminho Linux)");
            texto.AppendLine("Distro=" + Distro);
            texto.AppendLine("Pasta=" + Pasta);
            texto.AppendLine("Porta=" + Porta);
            texto.AppendLine("; chrome, edge ou padrao (navegador padrão do Windows, em aba comum)");
            texto.AppendLine("Navegador=" + Navegador);
            try { File.WriteAllText(Arquivo, texto.ToString(), new UTF8Encoding(false)); } catch (Exception) { }
        }
    }

    internal sealed class Aplicacao : ApplicationContext
    {
        private readonly Configuracao config;
        private readonly NotifyIcon bandeja;
        private readonly SynchronizationContext ui;
        private readonly string pastaDados;
        private readonly string arquivoLog;
        private Process servidor;
        private bool encerrando;

        public Aplicacao(Configuracao config, bool abrirJanela)
        {
            this.config = config;
            ui = SynchronizationContext.Current ?? new WindowsFormsSynchronizationContext();
            pastaDados = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "PainelMatricula");
            Directory.CreateDirectory(pastaDados);
            arquivoLog = Path.Combine(pastaDados, "servidor.log");

            var menu = new ContextMenuStrip();
            var abrir = new ToolStripMenuItem("Abrir painel", null, delegate { AbrirJanela(); });
            abrir.Font = new Font(abrir.Font, FontStyle.Bold);
            menu.Items.Add(abrir);
            menu.Items.Add(new ToolStripMenuItem("Abrir no navegador", null, delegate { AbrirNoNavegadorPadrao(); }));
            menu.Items.Add(new ToolStripSeparator());
            menu.Items.Add(new ToolStripMenuItem("Reiniciar servidor", null, delegate { Reiniciar(); }));
            menu.Items.Add(new ToolStripMenuItem("Ver log do servidor", null, delegate { VerLog(); }));
            menu.Items.Add(new ToolStripMenuItem("Configuração…", null, delegate { AbrirConfiguracao(); }));
            menu.Items.Add(new ToolStripSeparator());
            menu.Items.Add(new ToolStripMenuItem("Encerrar painel", null, delegate { Encerrar(); }));

            bandeja = new NotifyIcon();
            bandeja.Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath) ?? SystemIcons.Application;
            bandeja.Text = "Painel Matrícula — iniciando…";
            bandeja.ContextMenuStrip = menu;
            bandeja.Visible = true;
            bandeja.MouseClick += delegate(object s, MouseEventArgs e) { if (e.Button == MouseButtons.Left) AbrirJanela(); };

            ThreadPool.QueueUserWorkItem(delegate { Iniciar(abrirJanela); });
        }

        // ── Servidor ─────────────────────────────────────────────

        private void Iniciar(bool abrirAoFinal)
        {
            if (!Responde())
            {
                Avisar("Iniciando o painel…", "Subindo o servidor na WSL (" + config.Distro + ").", ToolTipIcon.Info);
                if (!SubirServidor()) return;

                var limite = DateTime.Now.AddSeconds(45);
                while (!Responde())
                {
                    if (servidor != null && servidor.HasExited)
                    {
                        Falhar("O servidor do painel fechou logo ao iniciar.");
                        return;
                    }
                    if (DateTime.Now > limite)
                    {
                        Falhar("O painel não respondeu em 45 segundos.");
                        return;
                    }
                    Thread.Sleep(500);
                }
            }

            Na(delegate { bandeja.Text = "Painel Matrícula — " + config.Url; });
            if (abrirAoFinal) AbrirJanela();
            else Avisar("Painel Matrícula pronto", config.Url + " — clique no ícone para abrir.", ToolTipIcon.Info);
        }

        private bool SubirServidor()
        {
            var comando = "XDEBUG_MODE=off exec php artisan serve --host=127.0.0.1 --port=" + config.Porta;
            var info = new ProcessStartInfo(Wsl(),
                "-d " + Distro() + " --cd " + Aspas(config.Pasta) + " -- bash -lc " + Aspas(comando));
            info.UseShellExecute = false;
            info.CreateNoWindow = true;
            info.RedirectStandardOutput = true;
            info.RedirectStandardError = true;
            info.StandardOutputEncoding = Encoding.UTF8;
            info.StandardErrorEncoding = Encoding.UTF8;

            try
            {
                File.AppendAllText(arquivoLog, Environment.NewLine + "==== " + DateTime.Now.ToString("dd/MM/yyyy HH:mm:ss") + " iniciando: wsl " + info.Arguments + Environment.NewLine);
                servidor = new Process();
                servidor.StartInfo = info;
                servidor.EnableRaisingEvents = true;
                servidor.OutputDataReceived += delegate(object s, DataReceivedEventArgs e) { Registrar(e.Data); };
                servidor.ErrorDataReceived += delegate(object s, DataReceivedEventArgs e) { Registrar(e.Data); };
                servidor.Exited += delegate
                {
                    if (encerrando) return;
                    Na(delegate { bandeja.Text = "Painel Matrícula — servidor parado"; });
                    Avisar("O servidor do painel parou", "Use \"Reiniciar servidor\" no ícone da bandeja.", ToolTipIcon.Warning);
                };
                servidor.Start();
                servidor.BeginOutputReadLine();
                servidor.BeginErrorReadLine();
                return true;
            }
            catch (Exception e)
            {
                Falhar("Não consegui executar o wsl.exe: " + e.Message);
                return false;
            }
        }

        private void PararServidor()
        {
            encerrando = true;
            // O "artisan serve" sobe um "php -S" filho; derruba os dois dentro da WSL.
            // "-e" executa direto, sem "bash -c": senão o pkill/pgrep acharia o próprio
            // bash que o chamou (a linha dele contém o padrão).
            Executar(Wsl(), "-d " + Distro() + " -e pkill -f " + Aspas("artisan serve --host=127.0.0.1 --port=" + config.Porta), 15000);
            Executar(Wsl(), "-d " + Distro() + " -e pkill -f " + Aspas("php.* -S 127.0.0.1:" + config.Porta + " "), 15000);
            try { if (servidor != null && !servidor.HasExited) servidor.Kill(); } catch (Exception) { }
            servidor = null;
        }

        private bool Responde() { return Responde(config); }

        public static bool Responde(Configuracao config)
        {
            try
            {
                var pedido = (HttpWebRequest) WebRequest.Create(config.UrlInterna + "/favicon.ico");
                pedido.Method = "GET";
                pedido.Timeout = 3000;
                pedido.AllowAutoRedirect = false;
                pedido.Proxy = null;
                using (pedido.GetResponse()) { }
                return true;
            }
            catch (WebException e)
            {
                // Qualquer resposta HTTP (até 404/500) quer dizer que o servidor está de pé.
                if (e.Response != null) { e.Response.Close(); return true; }
                return false;
            }
            catch (Exception)
            {
                return false;
            }
        }

        private bool ExecucaoEmAndamento()
        {
            // pgrep devolve 0 quando encontra o executor das rotinas do painel ("-e": veja PararServidor).
            return Executar(Wsl(), "-d " + Distro() + " -e pgrep -f " + Aspas("artisan painel:executar"), 15000) == 0;
        }

        // ── Ações do menu ────────────────────────────────────────

        private void AbrirJanela()
        {
            if (!Responde())
            {
                ThreadPool.QueueUserWorkItem(delegate { Iniciar(true); });
                return;
            }

            try { AbrirJanela(config); }
            catch (Exception e) { Falhar("Não consegui abrir o navegador: " + e.Message); }
        }

        /// <summary>Abre o painel numa janela própria (modo app do Chrome; Edge como alternativa).</summary>
        public static void AbrirJanela(Configuracao config)
        {
            var navegador = CaminhoDoNavegador(config);
            if (navegador == null) Process.Start(config.Url + "/");
            else Process.Start(navegador, "--app=" + config.Url + "/");
        }

        private void AbrirNoNavegadorPadrao()
        {
            try { Process.Start(config.Url + "/"); } catch (Exception) { }
        }

        private void Reiniciar()
        {
            ThreadPool.QueueUserWorkItem(delegate
            {
                Na(delegate { bandeja.Text = "Painel Matrícula — reiniciando…"; });
                PararServidor();
                encerrando = false;
                Iniciar(false);
            });
        }

        private void VerLog()
        {
            if (!File.Exists(arquivoLog)) File.WriteAllText(arquivoLog, "");
            try { Process.Start("notepad.exe", Aspas(arquivoLog)); } catch (Exception) { }
        }

        private void AbrirConfiguracao()
        {
            try { Process.Start("notepad.exe", Aspas(Configuracao.Arquivo)); } catch (Exception) { }
            MessageBox.Show("Depois de salvar a configuração, use \"Reiniciar servidor\" para aplicar.",
                "Painel Matrícula", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }

        private void Encerrar()
        {
            if (ExecucaoEmAndamento())
            {
                var resposta = MessageBox.Show(
                    "Há uma rotina em execução pelo painel.\n\n" +
                    "Ela roda num processo separado e continua depois que o painel fecha, " +
                    "mas para de vez se a WSL for desligada.\n\n" +
                    "Encerrar o painel mesmo assim?",
                    "Painel Matrícula", MessageBoxButtons.YesNo, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button2);
                if (resposta != DialogResult.Yes) return;
            }

            PararServidor();
            bandeja.Visible = false;
            bandeja.Dispose();
            ExitThread();
        }

        // ── Utilidades ───────────────────────────────────────────

        private static string CaminhoDoNavegador(Configuracao config)
        {
            if (config.Navegador == "padrao") return null;

            var candidatos = new List<string>();
            var pf86 = Environment.GetEnvironmentVariable("ProgramFiles(x86)") ?? "";
            var pf = Environment.GetEnvironmentVariable("ProgramW6432") ?? Environment.GetEnvironmentVariable("ProgramFiles") ?? "";
            var local = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
            var edge = new[] {
                Path.Combine(pf86, @"Microsoft\Edge\Application\msedge.exe"),
                Path.Combine(pf, @"Microsoft\Edge\Application\msedge.exe"),
            };
            var chrome = new[] {
                Path.Combine(pf, @"Google\Chrome\Application\chrome.exe"),
                Path.Combine(pf86, @"Google\Chrome\Application\chrome.exe"),
                Path.Combine(local, @"Google\Chrome\Application\chrome.exe"),
            };
            // Chrome por padrão; Edge só quando pedido no .ini ou se o Chrome não existir.
            if (config.Navegador == "edge") { candidatos.AddRange(edge); candidatos.AddRange(chrome); }
            else { candidatos.AddRange(chrome); candidatos.AddRange(edge); }

            foreach (var c in candidatos) if (File.Exists(c)) return c;
            return null;
        }

        // O wsl.exe não tira as aspas do nome da distro ("-d \"Ubuntu\"" falha com
        // WSL_E_DISTRO_NOT_FOUND). Nome de distro não tem espaço: passa sem aspas,
        // só com os caracteres que um nome de distro pode ter.
        private string Distro()
        {
            var limpo = new StringBuilder();
            foreach (var c in config.Distro) if (char.IsLetterOrDigit(c) || c == '-' || c == '_' || c == '.') limpo.Append(c);
            return limpo.Length > 0 ? limpo.ToString() : "Ubuntu";
        }

        private static string Wsl()
        {
            // Num processo 32 bits o System32 é redirecionado; o Sysnative aponta para o de 64.
            var windows = Environment.GetFolderPath(Environment.SpecialFolder.Windows);
            var nativo = Path.Combine(windows, @"Sysnative\wsl.exe");
            if (File.Exists(nativo)) return nativo;
            return Path.Combine(windows, @"System32\wsl.exe");
        }

        private static int Executar(string arquivo, string argumentos, int esperaMs)
        {
            try
            {
                var info = new ProcessStartInfo(arquivo, argumentos);
                info.UseShellExecute = false;
                info.CreateNoWindow = true;
                using (var p = Process.Start(info))
                {
                    if (!p.WaitForExit(esperaMs)) { try { p.Kill(); } catch (Exception) { } return -1; }
                    return p.ExitCode;
                }
            }
            catch (Exception)
            {
                return -1;
            }
        }

        private static string Aspas(string valor)
        {
            return "\"" + valor.Replace("\"", "\\\"") + "\"";
        }

        private void Registrar(string linha)
        {
            if (linha == null) return;
            try { lock (arquivoLog) File.AppendAllText(arquivoLog, linha + Environment.NewLine); } catch (Exception) { }
        }

        private void Avisar(string titulo, string texto, ToolTipIcon icone)
        {
            Na(delegate { bandeja.ShowBalloonTip(4000, titulo, texto, icone); });
        }

        private void Falhar(string motivo)
        {
            Na(delegate
            {
                bandeja.Text = "Painel Matrícula — falhou ao iniciar";
                MessageBox.Show(
                    motivo + "\n\n" +
                    "Confira se a WSL (" + config.Distro + ") está instalada, se a pasta do painel existe:\n" + config.Pasta +
                    "\n\ne se a porta " + config.Porta + " está livre. Detalhes em:\n" + arquivoLog,
                    "Painel Matrícula", MessageBoxButtons.OK, MessageBoxIcon.Error);
            });
        }

        private void Na(Action acao)
        {
            ui.Post(delegate { try { acao(); } catch (ObjectDisposedException) { } }, null);
        }
    }

    internal static class Programa
    {
        [STAThread]
        private static void Main(string[] argumentos)
        {
            // --bandeja: sobe o servidor e fica só na bandeja, sem abrir a janela
            // (útil para iniciar junto com o Windows).
            var soBandeja = Array.Exists(argumentos, delegate(string a) { return a.Equals("--bandeja", StringComparison.OrdinalIgnoreCase); });

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);

            var config = Configuracao.Carregar();
            bool primeira;
            using (var unico = new Mutex(true, @"Local\PainelMatriculaIniciador", out primeira))
            {
                if (!primeira)
                {
                    if (soBandeja) return;
                    // Já está na bandeja: espera o servidor (pode estar subindo) e abre outra janela.
                    var limite = DateTime.Now.AddSeconds(45);
                    while (!Aplicacao.Responde(config) && DateTime.Now < limite) Thread.Sleep(500);
                    try { Aplicacao.AbrirJanela(config); } catch (Exception) { }
                    return;
                }

                SynchronizationContext.SetSynchronizationContext(new WindowsFormsSynchronizationContext());
                Application.Run(new Aplicacao(config, !soBandeja));
            }
        }
    }
}
