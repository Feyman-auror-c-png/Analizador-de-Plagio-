# Analizador de Plagio

Aplicacion web sencilla para revisar posibles coincidencias de plagio en tesis, ensayos o documentos academicos. Permite subir un archivo PDF/TXT o pegar texto directamente, selecciona fragmentos representativos y los busca en internet para generar un reporte basico de similitud.

> Este proyecto es un MVP academico. Sirve como revision inicial, no reemplaza herramientas profesionales como Turnitin, iThenticate u otros sistemas institucionales.

## Caracteristicas

- Carga de documentos `.pdf` y `.txt`.
- Analisis por texto pegado manualmente.
- Extraccion basica de texto desde PDF.
- Seleccion automatica de fragmentos relevantes.
- Busquedas web con pausas para reducir bloqueos.
- Modos de revision: Suave, Balanceado y Profundo.
- Reporte con porcentaje estimado de coincidencia.
- Listado de fuentes encontradas con enlaces.
- Visualizacion de fragmentos coincidentes.
- Interfaz simple hecha con HTML y CSS.

## Tecnologias

- PHP 8
- HTML5
- CSS3
- Busqueda web mediante resultados publicos

## Requisitos

- PHP 8 o superior
- Navegador web moderno
- Conexion a internet

En Windows se puede usar PHP desde Laragon, XAMPP o una instalacion manual.

## Instalacion

Clona el repositorio:

```bash
git clone https://github.com/Feyman-auror-c-png/Analizador-de-Plagio-.git
cd Analizador-de-Plagio-
```

Inicia el servidor local:

```bash
php -S 127.0.0.1:8000
```

Si usas Laragon en Windows y PHP no esta en el PATH, puedes ejecutar:

```powershell
& 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' -S 127.0.0.1:8000
```

Luego abre en el navegador:

```text
http://127.0.0.1:8000
```

## Uso

1. Abre la aplicacion en el navegador.
2. Sube un archivo `.pdf` o `.txt`, o pega el texto en el formulario.
3. Selecciona el modo de revision:
   - **Suave**: menos busquedas, mas rapido.
   - **Balanceado**: opcion recomendada.
   - **Profundo**: mas busquedas, tarda mas.
4. Presiona **Analizar**.
5. Revisa el porcentaje estimado, las fuentes y los fragmentos encontrados.

Para tesis completas, se recomienda analizar por capitulos o secciones para obtener mejores resultados y evitar demasiadas solicitudes al buscador.

## Como funciona

El sistema realiza los siguientes pasos:

1. Obtiene el texto desde el PDF, TXT o formulario.
2. Limpia espacios y caracteres de control.
3. Divide el contenido en oraciones o fragmentos.
4. Selecciona fragmentos representativos del documento.
5. Busca esos fragmentos en la web.
6. Descarga texto de las paginas encontradas.
7. Compara el documento original contra las fuentes usando similitud por secuencias de palabras.
8. Genera un reporte con fuentes, coincidencias y porcentaje aproximado.

## Limitaciones

- El porcentaje es aproximado y orientativo.
- No detecta de forma confiable parafrasis profundas.
- No accede a bases de datos privadas, repositorios institucionales cerrados ni documentos protegidos.
- La extraccion de PDF es basica; los PDF escaneados como imagen requieren OCR antes del analisis.
- Las busquedas pueden fallar si el buscador limita solicitudes automatizadas.
- No debe usarse como unica prueba para acusaciones de plagio.

## Estructura del proyecto

```text
.
|-- index.php
`-- README.md
```

## Mejoras futuras

- Soporte para archivos `.docx`.
- Integracion con una API de busqueda oficial.
- OCR para PDF escaneados.
- Historial de analisis.
- Exportacion del reporte en PDF.
- Panel de administracion.
- Comparacion contra documentos locales cargados por el usuario.
- Modulo separado para senales de texto generado por IA.

## Aviso

Este software debe usarse como apoyo academico y herramienta de revision previa. Los resultados requieren interpretacion humana, especialmente en documentos con citas, bibliografia, metodologia estandarizada o texto legal/tecnico que puede repetirse naturalmente.
