import mysql.connector

try:
    db = mysql.connector.connect(
        host="localhost",
        user="root",  # แทนที่ด้วย username ของ MySQL (เช่น root)
        password="",  # แทนที่ด้วย password ของ MySQL
        database="waste_system"
    )
    cursor = db.cursor(dictionary=True)  # ใช้ dictionary=True เพื่อให้ได้ผลลัพธ์เป็น dict
    cursor.execute("SELECT trashId, name, Waste_type FROM trash LIMIT 5")
    print("ข้อมูลจากตาราง trash:")
    for row in cursor.fetchall():
        print(f"trashId: {row['trashId']}, ชื่อ: {row['name']}, ประเภท: {row['Waste_type']}")
    cursor.close()
    db.close()
    print("เชื่อมต่อฐานข้อมูลสำเร็จ!")
except Exception as e:
    print(f"เกิดข้อผิดพลาด: {e}")