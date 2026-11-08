import requests
from dotenv import load_dotenv
import os

load_dotenv()
api_key = os.getenv("GOOGLE_MAPS_API_KEY")

# ใช้พิกัดเดิมที่คุณให้มา
lat, lng = 19.987695, 99.8442494
waste_types = {
    "ขยะรีไซเคิล": ["recycling waste", "waste recycling center", "recycling disposal", "waste collection center"],
    "ขยะทั่วไป": "waste disposal site",
    "ขยะอันตราย": "hazardous waste disposal site"
}
waste_type = "ขยะรีไซเคิล"
for query in waste_types[waste_type]:
    url = f"https://maps.googleapis.com/maps/api/place/textsearch/json?query={query}&location={lat},{lng}&radius=30000&key={api_key}"
    response = requests.get(url)
    if response.status_code == 200:
        results = response.json().get("results", [])
        if results:
            place = results[0]
            place_name = place['name']
            place_lat = place['geometry']['location']['lat']
            place_lng = place['geometry']['location']['lng']
            maps_link = f"https://www.google.com/maps/search/?api=1&query={place_lat},{place_lng}"
            print(f"คำค้น: {query}")
            print(f"ศูนย์ที่ใกล้ที่สุด: {place_name}")
            print(f"ที่อยู่: {place.get('formatted_address', 'ไม่ระบุ')}")
            print(f"ลิงก์ Google Maps: {maps_link}")
            break
        else:
            print(f"คำค้น '{query}' ไม่พบศูนย์จัดการขยะ")
    else:
        print(f"เกิดข้อผิดพลาด: {response.status_code}, {response.text}")
        break