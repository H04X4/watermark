
from flask import Flask, request, jsonify
import cv2
import numpy as np
import os

app = Flask(__name__)

def create_white_mask(image, lower_yellow, upper_yellow):
    # Преобразование изображения в цветовое пространство HSV
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)

    # Определение диапазона желтого цвета в HSV
    lower_yellow = np.array(lower_yellow)
    upper_yellow = np.array(upper_yellow)

    # Создание маски на основе диапазона желтого цвета
    mask = cv2.inRange(hsv, lower_yellow, upper_yellow)

    # Создание копии изображения для замены желтого на белый
    result_image = image.copy()
    result_image[mask != 0] = [255, 255, 255]  # Замена желтого на белый

    return mask, result_image

def process_and_save_image(image_path, lower_yellow, upper_yellow, output_dir='mask'):
    # Создание папки для сохранения изображений
    os.makedirs(output_dir, exist_ok=True)

    # Чтение изображения
    image = cv2.imread(image_path)
    if image is None:
        raise ValueError(f"Изображение по пути {image_path} не найдено")

    # Создание маски и результирующего изображения
    mask, result_image = create_white_mask(image, lower_yellow, upper_yellow)

    # Формирование имен файлов для сохранения
    base_filename = os.path.basename(image_path)
    mask_path = os.path.join(output_dir, base_filename)

    # Сохранение маски и результирующего изображения
    cv2.imwrite(mask_path, result_image)

@app.route('/upload_manual', methods=['POST'])
def upload_image():
    if 'image' not in request.files:
        return jsonify({'error': 'No image part in the request'}), 400
    
    image_file = request.files['image']
    if image_file.filename == '':
        return jsonify({'error': 'No selected image file'}), 400
    
    image_path = os.path.join('draw', image_file.filename)
    image_file.save(image_path)
    
    return jsonify({'message': 'Image uploaded successfully'}), 200

def process_all_images(input_dir, lower_yellow, upper_yellow, output_dir='mask'):
    # Получение списка всех файлов с расширением .png в папке input
    input_files = [f for f in os.listdir(input_dir) if f.endswith('.png')]
    
    # Обработка каждого файла
    for input_file in input_files:
        image_path = os.path.join(input_dir, input_file)
        process_and_save_image(image_path, lower_yellow, upper_yellow, output_dir)


@app.route('/process_manual', methods=['POST'])
def process_images():
    lower_yellow = [20, 100, 100]
    upper_yellow = [30, 255, 255]
    input_dir = 'draw'
    output_dir = 'mask'
    
    try:
        process_all_images(input_dir, lower_yellow, upper_yellow, output_dir)
        return jsonify({'message': 'Image processing completed'}), 200
    except Exception as e:
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    app.run(debug=True)

