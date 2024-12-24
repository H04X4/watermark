import os
import requests
from PIL import Image
import io
import logging

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

url = 'http://94.247.210.28:5557/inpaint'


def send_inpaint_request(image_path, mask_path):
    try:
    
        files = {
            'image': ('input.png', open(image_path, 'rb'), 'image/png'),
            'mask': ('processed_watermark.png', open(mask_path, 'rb'), 'image/png'),
        }

        data = {
            'ldmSteps': '25',
            'ldmSampler': 'plms',
            'zitsWireframe': 'true',
            'hdStrategy': 'Original',
            'hdStrategyCropMargin': '196',
            'hdStrategyCropTrigerSize': '800',
            'hdStrategyResizeLimit': '2048',
            'prompt': '',
            'negativePrompt': '',
            'croperX': '256',
            'croperY': '128',
            'croperHeight': '512',
            'croperWidth': '512',
            'useCroper': 'false',
            'sdMaskBlur': '5',
            'sdStrength': '0.75',
            'sdSteps': '50',
            'sdGuidanceScale': '7.5',
            'sdSampler': 'ddim',
            'sdSeed': '-1',
            'sdMatchHistograms': 'false',
            'sdScale': '1',
            'cv2Radius': '5',
            'cv2Flag': 'INPAINT_NS',
            'paintByExampleSteps': '50',
            'paintByExampleGuidanceScale': '7.5',
            'paintByExampleSeed': '-1',
            'paintByExampleMaskBlur': '5',
            'paintByExampleMatchHistograms': 'false',
            'p2pSteps': '50',
            'p2pImageGuidanceScale': '1.5',
            'p2pGuidanceScale': '7.5',
            'controlnet_conditioning_scale': '0.4',
            'controlnet_method': 'control_v11p_sd15_canny',
        }

        
        response = requests.post(url, files=files, data=data)
     
       
        image = Image.open(io.BytesIO(response.content))

      
        output_path = os.path.join('output', os.path.basename(image_path))
        image.save(output_path)
        logging.info(f"Изображение успешно сохранено как '{output_path}'")

    except requests.exceptions.RequestException as e:
        logging.error(f"Ошибка при отправке запроса: {e}")


input_folder = 'input'
mask_folder = 'result'

# Перебираем все файлы PNG и JPG в папке input и result/mask
for filename in os.listdir(input_folder):
    if filename.endswith(".png") or filename.endswith(".jpg"):
        image_path = os.path.join(input_folder, filename)
        mask_path = os.path.join(mask_folder, filename)

        # Проверяем наличие соответствующего файла маски
        if os.path.exists(mask_path):
            send_inpaint_request(image_path, mask_path)
        else:
            logging.warning(f"Файл маски не найден для изображения: {image_path}")
