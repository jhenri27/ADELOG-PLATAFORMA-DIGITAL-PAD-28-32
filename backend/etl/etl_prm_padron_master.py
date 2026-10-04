#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
PIPELINE ETL MAESTRO: INGESTA DE PADRÓN ELECTORAL Y CATÁLOGO OFICIAL JCE
PLATAFORMA PAD/28-32 — PROYECTO POLÍTICO PASTORA ALTAGRACIA
Circunscripción 3 (Santo Domingo Este, Boca Chica, San Antonio de Guerra, San Luis)
"""

import os
import sys
import re
import glob
import time
import argparse
import pymysql
import fitz  # PyMuPDF

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

# Configuración de base de datos MySQL local
DB_HOST = "localhost"
DB_USER = "root"
DB_PASS = ""
DB_NAME = "pad_electoral_2832"
DB_PORT = 3306

# Rutas de Archivos Fuente
PATH_JCE_REPORT = r"F:\ADELOG\DATOS DE PADRONES\REPORTEV DE RECINTOS ACTUALES Y NUEVOS\Reporte_Recintos_Colegios_y_Electores_x_Distritos_SD_ESTE_Ago202.pdf"
PATH_NUEVOS_RECINTOS = r"F:\ADELOG\DATOS DE PADRONES\REPORTEV DE RECINTOS ACTUALES Y NUEVOS\Relacion de Nuevos Recintos Santo Domingo Este.pdf"
PATH_3RA_CIRC = r"F:\ADELOG\DATOS DE PADRONES\3RA_CIRCUNSCRIPCION\3RA. CIRCUNSCRIPCION-20260827T132134Z-1-001\3RA. CIRCUNSCRIPCION"
PATH_CIR_03 = r"F:\ADELOG\DATOS DE PADRONES\CIR_03"


def get_db_connection():
    return pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        port=DB_PORT,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False
    )


def validar_cedula_luhn(cedula_str):
    """Valida y limpia una cédula dominicana usando Luhn Mod 10."""
    clean = re.sub(r'[^0-9]', '', cedula_str)
    if len(clean) != 11:
        return False, clean
    
    # Algoritmo Luhn Mod 10 estándar JCE
    multipliers = [1, 2, 1, 2, 1, 2, 1, 2, 1, 2]
    total = 0
    for i in range(10):
        prod = int(clean[i]) * multipliers[i]
        if prod >= 10:
            prod = (prod // 10) + (prod % 10)
        total += prod
    
    check_digit = (10 - (total % 10)) % 10
    is_valid = (check_digit == int(clean[10]))
    
    formatted = f"{clean[0:3]}-{clean[3:10]}-{clean[10:11]}"
    return is_valid, formatted


def clean_phone(phone_str):
    """Normaliza teléfonos y celulares dominicanos."""
    if not phone_str:
        return ""
    digits = re.sub(r'[^0-9]', '', phone_str)
    if len(digits) == 10 and digits[:3] in ['809', '829', '849']:
        return f"{digits[:3]}-{digits[3:6]}-{digits[6:]}"
    elif len(digits) == 11 and digits[0] == '1' and digits[1:4] in ['809', '829', '849']:
        return f"{digits[1:4]}-{digits[4:7]}-{digits[7:]}"
    return phone_str.strip()


def seed_catalogo_jce(conn):
    """Extrae y siembra los recintos y colegios del reporte preliminar JCE y nuevos recintos."""
    print("\n--- FASE 1: EXTRACCIÓN DEL CATÁLOGO OFICIAL JCE (189 RECINTOS / 1,562 COLEGIOS) ---")
    if not os.path.exists(PATH_JCE_REPORT):
        print(f"[!] No se encontró el archivo JCE: {PATH_JCE_REPORT}")
        return

    doc = fitz.open(PATH_JCE_REPORT)
    cursor = conn.cursor()
    
    recintos_count = 0
    colegios_count = 0

    full_text = ""
    for page in doc:
        full_text += page.get_text("text") + "\n"

    # Regex para extraer bloques de recintos
    # Formato JCE:
    # 00001
    # COLEG. NTRA. SRA. DEL ROSARIO DE FATIMA       CALLE L,
    # INVI, LOS MINA
    # Colegio(s) Total
    # 20
    # 10,652
    lines = [l.strip() for l in full_text.split('\n') if l.strip()]
    i = 0
    current_recinto = None

    while i < len(lines):
        line = lines[i]
        if re.match(r'^\d{5}$', line):
            cod_rec = line
            desc_rec = lines[i+1] if i+1 < len(lines) else ""
            sec_rec = lines[i+2] if i+2 < len(lines) else ""
            
            # Insertar recinto
            cursor.execute("""
                INSERT INTO catalogo_recintos_jce 
                (codigo_recinto, nombre_recinto, direccion_recinto, sector, municipio, circunscripcion)
                VALUES (%s, %s, %s, %s, 'SANTO DOMINGO ESTE', '03')
                ON DUPLICATE KEY UPDATE 
                    nombre_recinto = VALUES(nombre_recinto),
                    sector = VALUES(sector)
            """, (cod_rec, desc_rec[:255], desc_rec[:500], sec_rec[:100]))
            recintos_count += 1
            current_recinto = cod_rec
            i += 3
            continue
        
        # Detectar colegios (4 dígitos o 4 dígitos + letra)
        m_col = re.match(r'^(\d{4}[A-Za-z]?)$', line)
        if m_col and current_recinto:
            col_num = m_col.group(1).upper()
            cursor.execute("""
                INSERT INTO catalogo_colegios_jce (colegio_numero, codigo_recinto)
                VALUES (%s, %s)
                ON DUPLICATE KEY UPDATE codigo_recinto = VALUES(codigo_recinto)
            """, (col_num, current_recinto))
            colegios_count += 1
        
        i += 1

    conn.commit()
    print(f"[OK] Catalogo JCE procesado: {recintos_count} recintos y {colegios_count} colegios registrados.")

    # Ingesta de 29 Nuevos Recintos
    if os.path.exists(PATH_NUEVOS_RECINTOS):
        print("\n--- ACTUALIZANDO CATALOGO CON LOS 29 NUEVOS RECINTOS (2021 - 2026) ---")
        doc_n = fitz.open(PATH_NUEVOS_RECINTOS)
        text_n = ""
        for p in doc_n:
            text_n += p.get_text("text") + "\n"
        
        nuevos_cods = re.findall(r'(\d{5})', text_n)
        for c in set(nuevos_cods):
            cursor.execute("""
                UPDATE catalogo_recintos_jce 
                SET es_nuevo = 1, ano_creacion = '2021-2026'
                WHERE codigo_recinto = %s
            """, (c,))
        conn.commit()
        print(f"[OK] {len(set(nuevos_cods))} recintos actualizados con bandera de NUEVA CREACION.")


def parse_padron_pdf(pdf_path):
    """Extrae todos los electores de un PDF de colegio electoral del PRM."""
    voters = []
    try:
        doc = fitz.open(pdf_path)
    except Exception as e:
        print(f"[!] Error al abrir {pdf_path}: {e}")
        return voters

    meta = {
        "provincia": "SANTO DOMINGO",
        "municipio": "SANTO DOMINGO ESTE",
        "circunscripcion": "03",
        "distrito_municipal": "",
        "codigo_recinto": "",
        "nombre_recinto": "",
        "colegio": "",
        "region": ""
    }

    # Determinar Región a partir del path
    path_lower = pdf_path.lower()
    if "región iiib" in path_lower or "region iiib" in path_lower or "región 3-b" in path_lower:
        meta["region"] = "REGION 3-B"
    elif "región iiia" in path_lower or "region iiia" in path_lower or "región 3-a" in path_lower:
        meta["region"] = "REGION 3-A"
    elif "región iiie" in path_lower or "region iiie" in path_lower or "región 3-e" in path_lower:
        meta["region"] = "REGION 3-E"
    elif "región iii" in path_lower or "region iii" in path_lower or "región 3" in path_lower:
        meta["region"] = "REGION 3"
    elif "san luis" in path_lower:
        meta["region"] = "SAN LUIS"
        meta["distrito_municipal"] = "SAN LUIS (DM)"
    elif "boca_chica" in path_lower or "boca chica" in path_lower:
        meta["region"] = "BOCA CHICA"
        meta["municipio"] = "BOCA CHICA"
        if "la_caleta" in path_lower or "la caleta" in path_lower:
            meta["region"] = "LA CALETA"
            meta["distrito_municipal"] = "LA CALETA (DM)"
    elif "guerra" in path_lower:
        meta["region"] = "GUERRA"
        meta["municipio"] = "SAN ANTONIO DE GUERRA"
        if "hato_viejo" in path_lower or "hato viejo" in path_lower:
            meta["distrito_municipal"] = "HATO VIEJO (DM)"

    for page_idx in range(len(doc)):
        text = doc[page_idx].get_text("text")
        lines = [l.strip() for l in text.split("\n") if l.strip()]
        
        # Extraer metadatos de cabecera si aún no están completos
        for idx, line in enumerate(lines[:30]):
            if line.startswith("Mun:") and idx + 1 < len(lines):
                meta["municipio"] = lines[idx+1].replace("223 - ", "").replace("226 - ", "").replace("227 - ", "").strip()
            elif line.startswith("DM:") and idx + 1 < len(lines):
                meta["distrito_municipal"] = lines[idx+1].strip()
            elif line.startswith("Rec:") and idx + 1 < len(lines):
                rec_raw = lines[idx+1].strip()
                m_rec = re.match(r'^(\d{5})\s*-\s*(.+)$', rec_raw)
                if m_rec:
                    meta["codigo_recinto"] = m_rec.group(1)
                    meta["nombre_recinto"] = m_rec.group(2).strip()
                else:
                    meta["nombre_recinto"] = rec_raw
            elif line == "Colegio" and idx > 0:
                meta["colegio"] = lines[idx-1].strip()
            elif line.startswith("Colegio:") and idx + 1 < len(lines):
                meta["colegio"] = lines[idx+1].strip()

        # Si el nombre del archivo es el colegio (ej. 1156.pdf)
        if not meta["colegio"]:
            base_fname = os.path.splitext(os.path.basename(pdf_path))[0]
            meta["colegio"] = base_fname.replace(".pdf", "").strip()

        # Normalizar colegio electoral aislando el código oficial de 4 dígitos (+ letra)
        if meta["colegio"]:
            m_col_clean = re.search(r'\b(\d{4}[A-Za-z]?)\b', meta["colegio"])
            if m_col_clean:
                meta["colegio"] = m_col_clean.group(1).upper()

        # Parsear electores en la página
        # Patrón típico:
        # Cédula: 223-0118007-5
        # Número orden: 1
        # Votó: PC
        # Nombre: ABRAHAM FELIZ, ALVARO
        # Tel: Tel: (829) 903-3446 Cel I:(829) 610-6469
        # Dir: PARAISO 21
        # PRM: 2016
        i = 0
        while i < len(lines):
            line = lines[i]
            # Detectar cédula
            m_ced = re.match(r'^(\d{3}-\d{7}-\d)$', line)
            if m_ced:
                cedula = m_ced.group(1)
                num_orden = None
                nombres = ""
                apellidos = ""
                tel_fijo = ""
                celular = ""
                direccion = ""
                militancia_hist = ""
                
                # Avanzar para capturar los campos del bloque
                j = i + 1
                while j < min(i + 15, len(lines)):
                    subline = lines[j]
                    if re.match(r'^\d{3}-\d{7}-\d$', subline):
                        break  # Siguiente elector
                    
                    if re.match(r'^\d{1,4}$', subline) and num_orden is None:
                        num_orden = int(subline)
                    elif "," in subline and not nombres and not subline.startswith("Dir:") and not subline.startswith("Tel:"):
                        # Formato: APELLIDOS, NOMBRES
                        parts = subline.split(",", 1)
                        apellidos = parts[0].strip()
                        nombres = parts[1].strip()
                    elif subline.startswith("Tel:") or "Cel I:" in subline:
                        # Extraer teléfonos
                        tels = re.findall(r'\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}', subline)
                        if len(tels) >= 1:
                            tel_fijo = clean_phone(tels[0])
                        if len(tels) >= 2:
                            celular = clean_phone(tels[1])
                    elif subline.startswith("Dir:"):
                        if j + 1 < len(lines) and not lines[j+1].startswith("PRM:") and not lines[j+1].startswith("PLD:"):
                            direccion = lines[j+1].strip()
                    elif subline.startswith("PRM:") or subline.startswith("PLD:") or subline.startswith("Conc:"):
                        militancia_hist += " " + subline
                    
                    j += 1

                if nombres or apellidos:
                    pos_rec = f"{meta['codigo_recinto'] or '00000'}-{num_orden or 0}"
                    voters.append({
                        "cedula": cedula,
                        "numero_orden": num_orden or 0,
                        "posicion_recinto": pos_rec,
                        "nombres": nombres or "CIUDADANO",
                        "apellidos": apellidos or "INSCRITO",
                        "telefono_fijo": tel_fijo,
                        "celular": celular,
                        "direccion": direccion,
                        "colegio_electoral": meta["colegio"],
                        "codigo_recinto": meta["codigo_recinto"],
                        "nombre_recinto": meta["nombre_recinto"],
                        "sector": meta.get("sector", ""),
                        "municipio": meta["municipio"],
                        "distrito_municipal": meta["distrito_municipal"],
                        "region": meta["region"],
                        "militancia_prm": 1,
                        "militancia_historica": militancia_hist.strip()
                    })
                i = j - 1
            i += 1

    return voters


def run_full_etl(limit_col=None):
    """Ejecuta el pipeline ETL completo con batch inserts."""
    print("=======================================================================")
    print("INICIANDO PIPELINE ETL MAESTRO: PADRÓN PRM / JCE CIRCUNSCRIPCIÓN 3")
    print("=======================================================================")
    start_time = time.time()
    
    conn = get_db_connection()
    
    # 1. Catálogo JCE
    seed_catalogo_jce(conn)
    
    # 2. Recolectar todos los PDFs
    pdf_files = []
    for path in [PATH_3RA_CIRC, PATH_CIR_03]:
        if os.path.exists(path):
            found = glob.glob(os.path.join(path, "**", "*.pdf"), recursive=True)
            # Filtrar los PDFs de reportes
            found = [f for f in found if "Reporte_Recintos" not in f and "Relacion de Nuevos" not in f]
            pdf_files.extend(found)

    pdf_files = sorted(list(set(pdf_files)))
    total_pdfs = len(pdf_files)
    print(f"\n--- FASE 2: EXTRACCIÓN DE ELECTORES DESDE {total_pdfs} COLEGIOS ELECTORALES ---")
    
    if limit_col:
        pdf_files = pdf_files[:limit_col]
        print(f"[*] Modo limitado: Procesando los primeros {limit_col} colegios.")

    cursor = conn.cursor()
    total_electores_ingestados = 0
    batch_voters = []
    BATCH_SIZE = 1000

    for idx, pdf in enumerate(pdf_files):
        voters = parse_padron_pdf(pdf)
        batch_voters.extend(voters)
        
        if len(batch_voters) >= BATCH_SIZE or idx == len(pdf_files) - 1:
            # Ejecutar batch insert
            sql = """
                INSERT INTO padron_maestro_consulta 
                (cedula, numero_orden, posicion_recinto, nombres, apellidos, telefono_fijo, celular, direccion, colegio_electoral, codigo_recinto, nombre_recinto, sector, municipio, distrito_municipal, region, militancia_prm, militancia_historica)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    numero_orden = VALUES(numero_orden),
                    posicion_recinto = VALUES(posicion_recinto),
                    nombres = VALUES(nombres),
                    apellidos = VALUES(apellidos),
                    telefono_fijo = IF(VALUES(telefono_fijo) != '', VALUES(telefono_fijo), telefono_fijo),
                    celular = IF(VALUES(celular) != '', VALUES(celular), celular),
                    direccion = IF(VALUES(direccion) != '', VALUES(direccion), direccion),
                    colegio_electoral = VALUES(colegio_electoral),
                    codigo_recinto = VALUES(codigo_recinto),
                    nombre_recinto = VALUES(nombre_recinto),
                    municipio = VALUES(municipio),
                    distrito_municipal = VALUES(distrito_municipal),
                    region = VALUES(region),
                    militancia_historica = VALUES(militancia_historica)
            """
            data_tuples = [
                (
                    v['cedula'], v['numero_orden'], v['posicion_recinto'], v['nombres'], v['apellidos'],
                    v['telefono_fijo'], v['celular'], v['direccion'],
                    v['colegio_electoral'], v['codigo_recinto'], v['nombre_recinto'],
                    v['sector'], v['municipio'], v['distrito_municipal'], v['region'],
                    v['militancia_prm'], v['militancia_historica']
                )
                for v in batch_voters
            ]
            cursor.executemany(sql, data_tuples)
            conn.commit()
            total_electores_ingestados += len(batch_voters)
            batch_voters = []

        if (idx + 1) % 25 == 0 or (idx + 1) == len(pdf_files):
            elapsed = round(time.time() - start_time, 1)
            percent = round(((idx + 1) / len(pdf_files)) * 100, 1)
            print(f"[{percent}%] Procesados {idx+1}/{len(pdf_files)} colegios | Electores acumulados: {total_electores_ingestados:,} | Tiempo: {elapsed}s")

    conn.close()
    total_time = round(time.time() - start_time, 1)
    print("\n=======================================================================")
    print(f"PIPELINE COMPLETADO: {total_electores_ingestados:,} Electores Ingestados en {total_time} segundos.")
    print("=======================================================================")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="ETL Maestro de Padrón Electoral Circunscripción 3")
    parser.add_argument("--mode", choices=["catalog", "sample", "full"], default="full", help="Modo de ejecución")
    parser.add_argument("--limit", type=int, default=None, help="Límite de colegios a procesar")
    args = parser.parse_args()

    if args.mode == "catalog":
        conn = get_db_connection()
        seed_catalogo_jce(conn)
        conn.close()
    elif args.mode == "sample":
        run_full_etl(limit_col=args.limit or 5)
    else:
        run_full_etl(limit_col=args.limit)
