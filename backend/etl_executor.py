import openpyxl
import csv
import json
import sys
import os
import argparse

def validar_cedula_luhn(ced):
    ced = ''.join(c for c in ced if c.isdigit())
    if len(ced) != 11:
        return False
    pesos = [1, 2, 1, 2, 1, 2, 1, 2, 1, 2]
    suma = 0
    for i in range(10):
        mult = int(ced[i]) * pesos[i]
        if mult >= 10:
            suma += (mult // 10) + (mult % 10)
        else:
            suma += mult
    digito_calculado = (10 - (suma % 10)) % 10
    return digito_calculado == int(ced[10])

def parse_row(row, mapping):
    record = {}
    for field, col_idx in mapping.items():
        if col_idx is not None and col_idx < len(row):
            val = str(row[col_idx]).strip() if row[col_idx] is not None else ""
            record[field] = val
        else:
            record[field] = ""
            
    # Saneamiento de Cédula
    ced = ''.join(c for c in record.get('cedula', '') if c.isdigit())
    ced_formateada = ""
    luhn_valido = False
    if len(ced) == 11:
        ced_formateada = f"{ced[0:3]}-{ced[3:10]}-{ced[10]}"
        luhn_valido = validar_cedula_luhn(ced)
    else:
        ced_formateada = record.get('cedula', '')
        
    record['cedula_clean'] = ced
    record['cedula_formateada'] = ced_formateada
    record['luhn_valido'] = luhn_valido
    
    # Saneamiento de Teléfono
    tel = ''.join(c for c in record.get('telefono', '') if c.isdigit())
    if len(tel) == 10 and tel[0:3] in ['809', '829', '849']:
        record['telefono_valido'] = True
    else:
        record['telefono_valido'] = False
    record['telefono_clean'] = tel
    
    # Saneamiento de Nombres y Apellidos
    nombres = record.get('nombres', '')
    apellidos = record.get('apellidos', '')
    if nombres and not apellidos:
        # Intentar dividir si solo viene en una columna
        partes = nombres.split(' ')
        if len(partes) > 1:
            record['nombres'] = ' '.join(partes[0:len(partes)//2])
            record['apellidos'] = ' '.join(partes[len(partes)//2:])
            
    return record

def process_excel(filepath, mapping):
    wb = openpyxl.load_workbook(filepath, read_only=True, data_only=True)
    sheet = wb.active
    records = []
    
    for r_idx, row in enumerate(sheet.iter_rows(min_row=2, values_only=True)):
        # Skip completely empty rows
        if not any(row):
            continue
        record = parse_row(row, mapping)
        records.append(record)
        
    return records

def process_csv(filepath, mapping):
    # Detect delimiter
    with open(filepath, 'r', encoding='utf-8-sig') as f:
        sample = f.read(2048)
        dialect = csv.Sniffer().sniff(sample)
        delimiter = dialect.delimiter
        
    records = []
    with open(filepath, 'r', encoding='utf-8-sig') as f:
        reader = csv.reader(f, delimiter=delimiter)
        for i, row in enumerate(reader):
            if i == 0:
                continue # Skip header
            if not row or not any(row):
                continue
            record = parse_row(row, mapping)
            records.append(record)
            
    return records

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('--file', required=True)
    parser.add_argument('--mapping', required=True) # Mapeo en formato JSON string
    args = parser.parse_args()
    
    filepath = args.file
    import base64
    mapping_str = args.mapping
    try:
        try:
            decoded = base64.b64decode(mapping_str).decode('utf-8')
            if decoded.startswith('{'):
                mapping_str = decoded
        except Exception:
            pass
        mapping = json.loads(mapping_str)
    except Exception as e:
        print(json.dumps({"exito": False, "mensaje": f"Mapeo de columnas inválido: {str(e)}"}))
        sys.exit(1)
        
    if not os.path.exists(filepath):
        print(json.dumps({"exito": False, "mensaje": "Archivo no encontrado."}))
        sys.exit(1)
        
    ext = os.path.splitext(filepath)[1].lower()
    try:
        if ext == '.xlsx':
            res_data = process_excel(filepath, mapping)
        elif ext in ['.csv', '.txt']:
            res_data = process_csv(filepath, mapping)
        else:
            print(json.dumps({"exito": False, "mensaje": "Formato de archivo no soportado."}))
            sys.exit(1)
            
        print(json.dumps({"exito": True, "datos": res_data}, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({"exito": False, "mensaje": f"Error al procesar archivo: {str(e)}"}))
