from onnx2keras import onnx_to_keras
import onnx
from tensorflow.keras.models import save_model
import re

def clean_name(name):
    # ลบ / ขึ้นต้นและขึ้นท้ายออก และแทนด้วย underscore (_)
    # หรือแค่ลบ / ขึ้นต้น-ขึ้นท้ายก็พอ
    return re.sub(r'^/+|/+$', '', name).replace('/', '_')

# โหลดโมเดล ONNX
onnx_model = onnx.load("model_fixed.onnx")

# แก้ชื่อ node ใน graph ให้ไม่มี / ขึ้นต้น-ขึ้นท้าย
for node in onnx_model.graph.node:
    node.name = clean_name(node.name)
    # บางที input/output ของ node ก็มีชื่อที่เป็นปัญหา เราอาจจะแก้ชื่อด้วยถ้าจำเป็น
    node.input[:] = [clean_name(x) for x in node.input]
    node.output[:] = [clean_name(x) for x in node.output]

# แปลงเป็น Keras model
k_model = onnx_to_keras(onnx_model, ['input'])

# บันทึกโมเดล Keras
save_model(k_model, 'converted_model.h5')
print("✅ แปลงไฟล์ ONNX เป็น Keras .h5 เรียบร้อย")
